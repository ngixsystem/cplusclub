"""C+CLub Windows agent. Fixed local queries only; never accepts remote commands."""
import argparse
import datetime as dt
import hashlib
import json
import math
import os
from pathlib import Path
import random
import re
import shutil
import subprocess
import time
import urllib.error
import urllib.request
import uuid

METRICS = ('cpu_temp', 'gpu_temp', 'cpu_load', 'gpu_load', 'ram_used_percent', 'disk_free_percent')

def temperature_values(sensors, configured):
    values = {}
    for metric, family in (('cpu_temp', 'cpu'), ('gpu_temp', 'gpu')):
        explicit = configured.get(metric)
        candidates = [s.get('Value') for s in sensors if
                      (s.get('Identifier') == explicit if explicit else
                       s.get('SensorType') == 'Temperature' and
                       family in str(s.get('Identifier', '')).strip('/').split('/')[0].lower())]
        valid = [float(v) for v in candidates if isinstance(v, (int, float))
                 and not isinstance(v, bool) and math.isfinite(v) and 0 <= v <= 150]
        values[metric] = max(valid) if valid else None
    return values

def powershell(script):
    result = subprocess.run(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', script], capture_output=True, text=True, timeout=15, check=True)
    return json.loads(result.stdout.lstrip('\ufeff'))

def fingerprint():
    value = powershell('(Get-CimInstance Win32_ComputerSystemProduct).UUID | ConvertTo-Json -Compress')
    if not value or value.lower() in ('ffffffff-ffff-ffff-ffff-ffffffffffff', '00000000-0000-0000-0000-000000000000'):
        raise RuntimeError('Machine UUID unavailable; provision an explicit per-device agent outside the shared image.')
    return hashlib.sha256(value.encode()).hexdigest()

def sample(config, simulate=False):
    data = dict.fromkeys(METRICS)
    status = {key: 'unavailable' for key in METRICS}
    if simulate:
        data.update(cpu_temp=config.get('simulate_cpu_temp', 45), cpu_load=20, ram_used_percent=40, disk_free_percent=60)
    else:
        try:
            system = powershell('$o=Get-CimInstance Win32_OperatingSystem; $p=Get-CimInstance Win32_Processor | Select-Object -First 1; @{cpu=$p.LoadPercentage; ram=(100*(1-$o.FreePhysicalMemory/$o.TotalVisibleMemorySize))} | ConvertTo-Json -Compress')
            data['cpu_load'] = system['cpu']
            data['ram_used_percent'] = system['ram']
        except (subprocess.SubprocessError, ValueError, KeyError):
            pass
        try:
            disk = shutil.disk_usage(config.get('disk_path', 'C:\\'))
            data['disk_free_percent'] = 100 * disk.free / disk.total
        except OSError:
            pass
        try:
            sensors = powershell('@(Get-CimInstance -Namespace root/LibreHardwareMonitor -ClassName Sensor -ErrorAction Stop | Select-Object Identifier,Value,SensorType) | ConvertTo-Json -Compress')
            data.update(temperature_values(sensors, config.get('sensor_ids', {})))
            values = {s['Identifier']: s['Value'] for s in sensors}
            for metric in ('gpu_load',):
                data[metric] = values.get(config.get('sensor_ids', {}).get(metric))
        except (subprocess.SubprocessError, ValueError, KeyError, TypeError):
            pass
    for key, value in data.items():
        if value is not None:
            status[key] = 'ok'
    return {'equipment_id': config['equipment_id'], 'observed_at': dt.datetime.now(dt.timezone.utc).isoformat(), **data, 'sensor_status': status}

def manifest_build(path, app_id):
    text = Path(path).read_text(encoding='utf-8', errors='strict')
    app = re.search(r'"appid"\s+"(\d+)"', text, re.I)
    build = re.search(r'"buildid"\s+"(\d+)"', text, re.I)
    if not app or app.group(1) != str(app_id) or not build:
        raise ValueError('Unsupported manifest')
    return build.group(1)

def local_versions(config):
    for app_id, path in config.get('steam_manifests', {}).items():
        if str(app_id) not in ('730', '570'):
            continue
        try:
            yield str(app_id), manifest_build(path, app_id)
        except (OSError, ValueError):
            print(f'{app_id}: manifest unavailable; no local version reported')
    file = config.get('faceit_file')
    if file:
        try:
            with open(file, 'rb') as handle:
                yield 'faceit_file', hashlib.file_digest(handle, 'sha256').hexdigest()
        except OSError:
            print('FACEIT monitored file unavailable; no release claim')

def post(config, path, payload):
    url = config['server'].rstrip('/') + '/api/v1/' + path
    if not url.startswith('https://') and not config.get('allow_insecure_local', False):
        raise ValueError('HTTPS required. allow_insecure_local is for an isolated lab only.')
    request = urllib.request.Request(url, json.dumps(payload).encode(), {'Content-Type': 'application/json', 'Authorization': 'Bearer ' + config['token']})
    with urllib.request.urlopen(request, timeout=20) as response:
        return json.load(response)

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--config', required=True)
    parser.add_argument('--simulate', action='store_true')
    parser.add_argument('--once', action='store_true')
    parser.add_argument('--bind-machine', action='store_true')
    args = parser.parse_args()
    path = Path(args.config)
    config = json.loads(path.read_text(encoding='utf-8-sig'))
    if args.bind_machine:
        config['machine_fingerprint'] = fingerprint()
        path.write_text(json.dumps(config, indent=2), encoding='utf-8')
        print('Bound. Keep this configuration outside the shared CCBoot image.')
        return
    if not args.simulate and (os.name != 'nt' or config.get('machine_fingerprint') != fingerprint()):
        raise SystemExit('Machine registration mismatch. Re-register this device; do not clone tokens.')
    if args.simulate:
        print('SIMULATION: synthetic measurements, isolated lab only')
    if not args.once:
        time.sleep(random.uniform(0, 60))
    while True:
        packet = {'batch_id': str(uuid.uuid4()), 'samples': [sample(config, args.simulate)]}
        # All retries use the SAME UUID. Expired samples are discarded on the next loop.
        for attempt in range(3):
            try:
                post(config, 'telemetry', packet)
                break
            except urllib.error.HTTPError as error:
                print('Ingest HTTP', error.code)
                if error.code in (401, 403):
                    raise SystemExit('Agent token rejected; manual registration required')
                if error.code < 500 and error.code != 429:
                    break
                time.sleep(min(60, int(error.headers.get('Retry-After', '10'))))
            except (OSError, ValueError):
                print('Ingest unavailable (details omitted to protect credentials)')
                time.sleep(2 ** attempt)
        for product, value in local_versions(config):
            try:
                post(config, 'local-version', {'equipment_id': config['equipment_id'], 'product': product, 'value': value})
            except (OSError, ValueError):
                print('Local version delivery unavailable')
        if args.once:
            return
        interval = min(300, max(10, float(config.get('interval_seconds', 15))))
        time.sleep(interval + random.uniform(0, 2))

if __name__ == '__main__':
    main()

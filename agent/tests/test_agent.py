import importlib.util
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('cclub_agent', Path(__file__).resolve().parents[1] / 'agent.py')
agent = importlib.util.module_from_spec(spec)
spec.loader.exec_module(agent)

class AgentTest(unittest.TestCase):
    def test_ping_output_in_english_and_russian_windows(self):
        en = 'Reply from 8.8.8.8: bytes=32 time=14ms TTL=117\r\nReply from 8.8.8.8: bytes=32 time<1ms TTL=117\r\nRequest timed out.'
        ru = '\u041e\u0442\u0432\u0435\u0442 \u043e\u0442 1.1.1.1: \u0447\u0438\u0441\u043b\u043e \u0431\u0430\u0439\u0442=32 \u0432\u0440\u0435\u043c\u044f=23\u043c\u0441 TTL=57'
        self.assertEqual(agent.parse_ping(en), [14.0, 1.0])
        self.assertEqual(agent.parse_ping(ru.encode('cp866').decode('latin-1')), [23.0])
        self.assertEqual(agent.summarize(3, 5, [10, 20, 10]), {'target_id': 3, 'sent': 5, 'received': 3, 'rtt_avg_ms': 13.33, 'rtt_max_ms': 20, 'jitter_ms': 10.0})
        self.assertEqual(agent.summarize(3, 5, [])['received'], 0)
        self.assertIsNone(agent.measure({'id': 1, 'host': '-n 1 evil'}))

    def test_cpu_gpu_detection_and_explicit_override(self):
        sensors = [
            {'Identifier':'/intelcpu/0/temperature/0','SensorType':'Temperature','Value':49},
            {'Identifier':'/intelcpu/0/temperature/1','SensorType':'Temperature','Value':80},
            {'Identifier':'/nvidiagpu/0/temperature/0','SensorType':'Temperature','Value':60},
        ]
        self.assertEqual(agent.temperature_values(sensors, {}), {'cpu_temp':80,'gpu_temp':60})
        self.assertEqual(agent.temperature_values(sensors, {'cpu_temp':'/intelcpu/0/temperature/0'})['cpu_temp'], 49)
        self.assertIsNone(agent.temperature_values(sensors, {'cpu_temp':'missing'})['cpu_temp'])

    def test_invalid_temperatures_are_not_green_zeroes(self):
        sensors=[{'Identifier':'/amdcpu/0/temperature/0','SensorType':'Temperature','Value':float('nan')}]
        self.assertIsNone(agent.temperature_values(sensors,{})['cpu_temp'])
        self.assertIsNone(agent.temperature_values([], {})['gpu_temp'])
    def test_missing_sensors_remain_null_in_simulation(self):
        packet = agent.sample({'equipment_id': 1, 'simulate_cpu_temp': 0}, simulate=True)
        self.assertEqual(packet['cpu_temp'], 0)
        self.assertEqual(packet['sensor_status']['cpu_temp'], 'ok')
        self.assertIsNone(packet['gpu_temp'])
        self.assertEqual(packet['sensor_status']['gpu_temp'], 'unavailable')

    def test_manifest_validates_app_id(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'appmanifest_730.acf'
            path.write_text('"AppState" { "appid" "730" "buildid" "12345" }')
            self.assertEqual(agent.manifest_build(path, 730), '12345')
            with self.assertRaises(ValueError):
                agent.manifest_build(path, 570)

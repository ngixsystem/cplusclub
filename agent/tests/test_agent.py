import importlib.util
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('cclub_agent', Path(__file__).resolve().parents[1] / 'agent.py')
agent = importlib.util.module_from_spec(spec)
spec.loader.exec_module(agent)

class AgentTest(unittest.TestCase):
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

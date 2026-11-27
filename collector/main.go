// C+CLub SNMP collector: runs inside the club network, reads port state and learned MAC
// addresses from the club's switches (read-only SNMP) and sends them to C+CLub over HTTPS.
// It never changes anything on the switches and accepts no remote commands.
package main

import (
	"bytes"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/gosnmp/gosnmp"
)

const version = "1.1.0"

const (
	oidSysDescr     = ".1.3.6.1.2.1.1.1.0"
	oidSysUpTime    = ".1.3.6.1.2.1.1.3.0"
	oidIfDescr      = ".1.3.6.1.2.1.2.2.1.2"
	oidIfType       = ".1.3.6.1.2.1.2.2.1.3"
	oidIfSpeed      = ".1.3.6.1.2.1.2.2.1.5"
	oidIfOperStatus = ".1.3.6.1.2.1.2.2.1.8"
	oidIfName       = ".1.3.6.1.2.1.31.1.1.1.1"
	oidIfHighSpeed  = ".1.3.6.1.2.1.31.1.1.1.15"
	oidIfAlias      = ".1.3.6.1.2.1.31.1.1.1.18"
	oidBasePortIf   = ".1.3.6.1.2.1.17.1.4.1.2"     // dot1dBasePortIfIndex: bridge port -> ifIndex
	oidQFdbPort     = ".1.3.6.1.2.1.17.7.1.2.2.1.2" // dot1qTpFdbPort: vlan.mac -> bridge port
	oidFdbPort      = ".1.3.6.1.2.1.17.4.3.1.2"     // dot1dTpFdbPort: mac -> bridge port
	oidBridgeAddr   = ".1.3.6.1.2.1.17.1.1.0"       // dot1dBaseBridgeAddress: switch MAC
	oidEntClass     = ".1.3.6.1.2.1.47.1.1.1.1.5"   // entPhysicalClass (3 = chassis)
	oidEntSoftware  = ".1.3.6.1.2.1.47.1.1.1.1.10"  // entPhysicalSoftwareRev
	oidEntSerial    = ".1.3.6.1.2.1.47.1.1.1.1.11"  // entPhysicalSerialNum
	oidEntModel     = ".1.3.6.1.2.1.47.1.1.1.1.13"  // entPhysicalModelName
)

type Config struct {
	Server string `json:"server"`
	Token  string `json:"token"`
}

type Switch struct {
	ID        int    `json:"id"`
	Name      string `json:"name"`
	Host      string `json:"host"`
	Port      uint16 `json:"port"`
	Version   string `json:"version"`
	Community string `json:"community"`
}

type Remote struct {
	Interval int      `json:"interval"`
	Switches []Switch `json:"switches"`
}

type Port struct {
	IfIndex   int    `json:"if_index"`
	Name      string `json:"name"`
	Alias     string `json:"alias,omitempty"`
	Oper      int    `json:"oper"`
	SpeedMbps int    `json:"speed_mbps"`
}

type Fdb struct {
	Mac     string `json:"mac"`
	IfIndex int    `json:"if_index"`
}

type Result struct {
	ID        int    `json:"id"`
	OK        bool   `json:"ok"`
	Error     string `json:"error,omitempty"`
	SysDescr  string `json:"sys_descr,omitempty"`
	UptimeSec uint64 `json:"uptime_seconds,omitempty"`
	Serial    string `json:"serial,omitempty"`
	Model     string `json:"model,omitempty"`
	Firmware  string `json:"firmware,omitempty"`
	BaseMac   string `json:"base_mac,omitempty"`
	Ports     []Port `json:"ports"`
	Fdb       []Fdb  `json:"fdb"`
}

var httpClient = &http.Client{Timeout: 30 * time.Second}

func main() {
	dir := defaultDir()
	cfgPath := flag.String("config", filepath.Join(dir, "config.json"), "путь к config.json")
	once := flag.Bool("once", false, "один опрос и выход")
	test := flag.String("test", "", "проверить коммутатор: -test 192.168.1.10 -community public")
	community := flag.String("community", "public", "community для -test")
	snmpPort := flag.Uint("port", 161, "UDP-порт SNMP для -test")
	flag.Parse()

	if *test != "" {
		r := poll(Switch{ID: 0, Host: *test, Port: uint16(*snmpPort), Version: "2c", Community: *community})
		printSummary(r)
		return
	}

	setupLog(dir)
	cfg, err := loadConfig(*cfgPath)
	if err != nil {
		log.Fatalf("конфиг: %v", err)
	}
	log.Printf("C+CLub SNMP collector %s запущен, сервер %s", version, cfg.Server)
	for {
		interval := cycle(cfg)
		if *once {
			return
		}
		time.Sleep(time.Duration(interval) * time.Second)
	}
}

func cycle(cfg Config) int {
	remote, err := fetchConfig(cfg)
	if err != nil {
		log.Printf("не удалось получить список коммутаторов: %v", err)
		return 60
	}
	results := make([]Result, len(remote.Switches))
	var wg sync.WaitGroup
	for i, sw := range remote.Switches {
		wg.Add(1)
		go func(i int, sw Switch) {
			defer wg.Done()
			results[i] = poll(sw)
		}(i, sw)
	}
	wg.Wait()
	for _, r := range results {
		if !r.OK {
			log.Printf("коммутатор %d: %s", r.ID, r.Error)
		}
	}
	if err := sendReport(cfg, results); err != nil {
		log.Printf("не удалось отправить отчёт: %v", err)
	}
	if remote.Interval < 30 {
		return 30
	}
	return remote.Interval
}

func poll(sw Switch) (res Result) {
	res = Result{ID: sw.ID, Ports: []Port{}, Fdb: []Fdb{}}
	defer func() {
		if p := recover(); p != nil {
			res.OK, res.Error = false, fmt.Sprintf("сбой опроса: %v", p)
		}
	}()
	port := sw.Port
	if port == 0 {
		port = 161
	}
	g := &gosnmp.GoSNMP{Target: sw.Host, Port: port, Community: sw.Community, Version: gosnmp.Version2c,
		Timeout: 3 * time.Second, Retries: 2, MaxRepetitions: 25}
	if sw.Version == "1" {
		g.Version = gosnmp.Version1
	}
	if err := g.Connect(); err != nil {
		res.Error = "подключение: " + err.Error()
		return
	}
	defer g.Conn.Close()

	sys, err := g.Get([]string{oidSysDescr, oidSysUpTime, oidBridgeAddr})
	if err != nil {
		res.Error = "нет ответа по SNMP (проверьте IP, community и что SNMP включён): " + err.Error()
		return
	}
	for _, v := range sys.Variables {
		switch v.Name {
		case oidSysDescr:
			if b, ok := v.Value.([]byte); ok {
				res.SysDescr = strings.TrimSpace(string(b))
			}
		case oidSysUpTime:
			res.UptimeSec = gosnmp.ToBigInt(v.Value).Uint64() / 100
		case oidBridgeAddr:
			if b, ok := v.Value.([]byte); ok && len(b) == 6 {
				res.BaseMac = fmt.Sprintf("%02x%02x%02x%02x%02x%02x", b[0], b[1], b[2], b[3], b[4], b[5])
			}
		}
	}

	walk := func(oid string) map[string]gosnmp.SnmpPDU {
		out := map[string]gosnmp.SnmpPDU{}
		fn := func(p gosnmp.SnmpPDU) error {
			out[strings.TrimPrefix(strings.TrimPrefix(p.Name, oid), ".")] = p
			return nil
		}
		if g.Version == gosnmp.Version1 {
			_ = g.Walk(oid, fn)
		} else {
			_ = g.BulkWalk(oid, fn)
		}
		return out
	}

	// Serial / model / firmware (ENTITY-MIB): the chassis entry, else the first entry that has one.
	classes, serials, models, soft := walk(oidEntClass), walk(oidEntSerial), walk(oidEntModel), walk(oidEntSoftware)
	keys := make([]string, 0, len(serials))
	for k := range serials {
		keys = append(keys, k)
	}
	sort.Slice(keys, func(i, j int) bool {
		ci := gosnmp.ToBigInt(classes[keys[i]].Value).Int64() == 3
		cj := gosnmp.ToBigInt(classes[keys[j]].Value).Int64() == 3
		if ci != cj {
			return ci
		}
		var a, b int
		fmt.Sscanf(keys[i], "%d", &a)
		fmt.Sscanf(keys[j], "%d", &b)
		return a < b
	})
	for _, k := range keys {
		if sn := str(serials[k]); sn != "" {
			res.Serial, res.Model, res.Firmware = sn, str(models[k]), str(soft[k])
			break
		}
	}

	descr, types, oper := walk(oidIfDescr), walk(oidIfType), walk(oidIfOperStatus)
	names, alias, high, speed := walk(oidIfName), walk(oidIfAlias), walk(oidIfHighSpeed), walk(oidIfSpeed)
	physical := map[int]bool{6: true, 62: true, 69: true, 117: true}
	anyPhysical := false
	for _, t := range types {
		if physical[int(gosnmp.ToBigInt(t.Value).Int64())] {
			anyPhysical = true
			break
		}
	}
	for idx, d := range descr {
		var ifIndex int
		if _, err := fmt.Sscanf(idx, "%d", &ifIndex); err != nil {
			continue
		}
		if t, ok := types[idx]; ok && anyPhysical && !physical[int(gosnmp.ToBigInt(t.Value).Int64())] {
			continue
		}
		p := Port{IfIndex: ifIndex, Name: str(names[idx]), Alias: str(alias[idx]), Oper: int(gosnmp.ToBigInt(oper[idx].Value).Int64())}
		if p.Name == "" {
			p.Name = str(d)
		}
		if h, ok := high[idx]; ok && gosnmp.ToBigInt(h.Value).Int64() > 0 {
			p.SpeedMbps = int(gosnmp.ToBigInt(h.Value).Int64())
		} else if s, ok := speed[idx]; ok {
			p.SpeedMbps = int(gosnmp.ToBigInt(s.Value).Int64() / 1000000)
		}
		res.Ports = append(res.Ports, p)
	}
	sort.Slice(res.Ports, func(i, j int) bool { return res.Ports[i].IfIndex < res.Ports[j].IfIndex })
	if len(res.Ports) == 0 {
		res.Error = "коммутатор ответил, но не отдал таблицу портов (IF-MIB)"
		return
	}

	bridge := map[int]int{}
	for idx, p := range walk(oidBasePortIf) {
		var bp int
		if _, err := fmt.Sscanf(idx, "%d", &bp); err == nil {
			bridge[bp] = int(gosnmp.ToBigInt(p.Value).Int64())
		}
	}
	toIf := func(bp int) int {
		if v, ok := bridge[bp]; ok {
			return v
		}
		return bp
	}
	seen := map[string]bool{}
	add := func(macParts []string, bp int) {
		if len(macParts) != 6 || bp <= 0 {
			return
		}
		var b [6]int
		for i, s := range macParts {
			if _, err := fmt.Sscanf(s, "%d", &b[i]); err != nil {
				return
			}
		}
		mac := fmt.Sprintf("%02x%02x%02x%02x%02x%02x", b[0], b[1], b[2], b[3], b[4], b[5])
		key := fmt.Sprintf("%s/%d", mac, bp)
		if seen[key] {
			return
		}
		seen[key] = true
		res.Fdb = append(res.Fdb, Fdb{Mac: mac, IfIndex: toIf(bp)})
	}
	for idx, p := range walk(oidQFdbPort) { // vlan.m1.m2.m3.m4.m5.m6
		parts := strings.Split(idx, ".")
		if len(parts) == 7 {
			add(parts[1:], int(gosnmp.ToBigInt(p.Value).Int64()))
		}
	}
	if len(res.Fdb) == 0 {
		for idx, p := range walk(oidFdbPort) {
			add(strings.Split(idx, "."), int(gosnmp.ToBigInt(p.Value).Int64()))
		}
	}
	res.OK = true
	return
}

func str(p gosnmp.SnmpPDU) string {
	if b, ok := p.Value.([]byte); ok {
		return strings.TrimSpace(string(b))
	}
	return ""
}

func fetchConfig(cfg Config) (Remote, error) {
	var r Remote
	req, _ := http.NewRequest("GET", strings.TrimRight(cfg.Server, "/")+"/api/v1/snmp/config", nil)
	body, err := do(cfg, req)
	if err != nil {
		return r, err
	}
	return r, json.Unmarshal(body, &r)
}

func sendReport(cfg Config, results []Result) error {
	payload, _ := json.Marshal(map[string]any{"switches": results})
	req, _ := http.NewRequest("POST", strings.TrimRight(cfg.Server, "/")+"/api/v1/snmp/report", bytes.NewReader(payload))
	req.Header.Set("Content-Type", "application/json")
	_, err := do(cfg, req)
	return err
}

func do(cfg Config, req *http.Request) ([]byte, error) {
	host, _ := os.Hostname()
	req.Header.Set("Authorization", "Bearer "+cfg.Token)
	req.Header.Set("Accept", "application/json")
	req.Header.Set("X-Collector-Version", version)
	req.Header.Set("X-Collector-Host", host)
	resp, err := httpClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(io.LimitReader(resp.Body, 4<<20))
	if resp.StatusCode != 200 {
		return nil, fmt.Errorf("HTTP %d: %s", resp.StatusCode, strings.TrimSpace(string(body[:min(len(body), 300)])))
	}
	return body, nil
}

func loadConfig(path string) (Config, error) {
	var c Config
	b, err := os.ReadFile(path)
	if err != nil {
		return c, err
	}
	if err := json.Unmarshal(bytes.TrimPrefix(b, []byte("\xef\xbb\xbf")), &c); err != nil {
		return c, err
	}
	if c.Server == "" || !strings.HasPrefix(c.Token, "ccs_") {
		return c, fmt.Errorf("в %s нужны server и token", path)
	}
	return c, nil
}

func defaultDir() string {
	if pd := os.Getenv("ProgramData"); pd != "" {
		return filepath.Join(pd, "CClubSNMP")
	}
	exe, _ := os.Executable()
	return filepath.Dir(exe)
}

func setupLog(dir string) {
	_ = os.MkdirAll(dir, 0o755)
	path := filepath.Join(dir, "collector.log")
	if st, err := os.Stat(path); err == nil && st.Size() > 1<<20 {
		_ = os.Rename(path, path+".old")
	}
	if f, err := os.OpenFile(path, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o644); err == nil {
		log.SetOutput(io.MultiWriter(os.Stdout, f))
	}
}

func printSummary(r Result) {
	if !r.OK {
		fmt.Println("ОШИБКА:", r.Error)
		os.Exit(1)
	}
	fmt.Printf("Коммутатор: %s\nСерийный номер: %s\nМодель: %s\nПрошивка: %s\nMAC: %s\nАптайм: %d ч\nПортов: %d, MAC-записей: %d\n\n",
		r.SysDescr, orDash(r.Serial), orDash(r.Model), orDash(r.Firmware), orDash(r.BaseMac), r.UptimeSec/3600, len(r.Ports), len(r.Fdb))
	count := map[int]int{}
	for _, f := range r.Fdb {
		count[f.IfIndex]++
	}
	for _, p := range r.Ports {
		state := "выкл"
		if p.Oper == 1 {
			state = fmt.Sprintf("%d Мбит/с", p.SpeedMbps)
		}
		fmt.Printf("  %-28s %-12s MAC: %d\n", p.Name, state, count[p.IfIndex])
	}
}

func orDash(s string) string {
	if s == "" {
		return "— (коммутатор не отдаёт)"
	}
	return s
}

package audit

import (
	"bufio"
	"encoding/json"
	"os"
	"path/filepath"
	"sync"
	"time"
)

type Logger struct { mu sync.Mutex; path string }

func New(path string) *Logger { return &Logger{path: path} }

func (l *Logger) Write(event map[string]any) {
	event["time"] = time.Now().UTC().Format(time.RFC3339Nano)
	b, err := json.Marshal(event)
	if err != nil { return }
	l.mu.Lock()
	defer l.mu.Unlock()
	_ = os.MkdirAll(filepath.Dir(l.path), 0700)
	f, err := os.OpenFile(l.path, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0600)
	if err != nil { return }
	defer f.Close()
	_, _ = f.Write(append(b, '\n'))
}

func (l *Logger) RecentConsoleActions(serviceID int64, limit int) []map[string]any {
	if limit < 1 || limit > 100 { limit = 25 }
	l.mu.Lock()
	defer l.mu.Unlock()
	f, err := os.Open(l.path)
	if err != nil { return nil }
	defer f.Close()
	if info, statErr := f.Stat(); statErr == nil && info.Size() > 2<<20 {
		_, _ = f.Seek(info.Size()-(2<<20), 0)
	}
	scanner := bufio.NewScanner(f)
	scanner.Buffer(make([]byte, 64*1024), 1024*1024)
	events := make([]map[string]any, 0, limit)
	for scanner.Scan() {
		var event map[string]any
		if json.Unmarshal(scanner.Bytes(), &event) != nil || event["event"] != "console_vm_action" {
			continue
		}
		value, ok := event["service_id"].(float64)
		if !ok || int64(value) != serviceID { continue }
		public := map[string]any{
			"event": event["event"], "action": event["action"],
			"success": event["success"], "time": event["time"],
		}
		events = append(events, public)
		if len(events) > limit { events = events[1:] }
	}
	for left, right := 0, len(events)-1; left < right; left, right = left+1, right-1 {
		events[left], events[right] = events[right], events[left]
	}
	return events
}

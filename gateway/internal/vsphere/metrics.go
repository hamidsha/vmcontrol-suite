package vsphere

import (
	"context"
	"fmt"
	"time"

	"github.com/vmware/govmomi/performance"
	"github.com/vmware/govmomi/vim25/types"
)

type VMMetrics struct {
	Range      string               `json:"range"`
	Interval   int32                `json:"interval_seconds"`
	Timestamps []string             `json:"timestamps"`
	Series     map[string][]float64 `json:"series"`
}

func (m *Manager) Metrics(ctx context.Context, id, instanceUUID, rangeName string) (*VMMetrics, error) {
	interval, maxSamples := int32(300), int32(288)
	switch rangeName {
	case "week":
		interval, maxSamples = 1800, 336
	case "month":
		interval, maxSamples = 7200, 360
	case "day":
	default:
		return nil, fmt.Errorf("unsupported metrics range %q", rangeName)
	}

	c, err := m.client(ctx, id)
	if err != nil {
		return nil, err
	}
	vm, _, err := m.GetVM(ctx, id, instanceUUID)
	if err != nil {
		return nil, err
	}

	metricNames := []string{
		"cpu.usage.average",
		"mem.usage.average",
		"net.received.average",
		"net.transmitted.average",
		"disk.read.average",
		"disk.write.average",
	}
	manager := performance.NewManager(c.Client)
	spec := types.PerfQuerySpec{
		MaxSample:  maxSamples,
		MetricId:   []types.PerfMetricId{{Instance: ""}},
		IntervalId: interval,
	}
	raw, err := manager.SampleByName(ctx, spec, metricNames, []types.ManagedObjectReference{vm.Reference()})
	if err != nil {
		return nil, fmt.Errorf("query VM performance: %w", err)
	}
	metrics, err := manager.ToMetricSeries(ctx, raw)
	if err != nil {
		return nil, fmt.Errorf("decode VM performance: %w", err)
	}

	result := &VMMetrics{
		Range: rangeName,
		Interval: interval,
		Series: make(map[string][]float64),
	}
	if len(metrics) == 0 {
		return result, nil
	}
	for _, sample := range metrics[0].SampleInfo {
		result.Timestamps = append(result.Timestamps, sample.Timestamp.UTC().Format(time.RFC3339))
	}
	for _, series := range metrics[0].Value {
		values := make([]float64, len(series.Value))
		for index, value := range series.Value {
			if value < 0 {
				values[index] = -1
				continue
			}
			values[index] = float64(value)
			if series.Name == "cpu.usage.average" || series.Name == "mem.usage.average" {
				values[index] = values[index] / 100
			}
		}
		result.Series[series.Name] = values
	}
	return result, nil
}

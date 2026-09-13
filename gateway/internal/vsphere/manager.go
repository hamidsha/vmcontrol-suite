package vsphere

import (
	"context"
	"errors"
	"fmt"
	"net/url"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/vmware/govmomi"
	"github.com/vmware/govmomi/find"
	"github.com/vmware/govmomi/object"
	"github.com/vmware/govmomi/session"
	"github.com/vmware/govmomi/view"
	"github.com/vmware/govmomi/vim25"
	"github.com/vmware/govmomi/vim25/mo"
	"github.com/vmware/govmomi/vim25/soap"
	"github.com/vmware/govmomi/vim25/types"

	"github.com/hamidsha/vmcontrol-suite/gateway/internal/config"
)

type Manager struct {
	configs map[string]config.VCenter
	timeout time.Duration
	mu      sync.Mutex
	clients map[string]*govmomi.Client
}

type VMInfo struct {
	VCenterID         string   `json:"vcenter_id"`
	Name              string   `json:"name"`
	InstanceUUID      string   `json:"instance_uuid"`
	ManagedObjectID   string   `json:"managed_object_id"`
	InventoryPath     string   `json:"inventory_path"`
	PowerState        string   `json:"power_state"`
	GuestOS           string   `json:"guest_os"`
	IPAddress         string   `json:"ip_address"`
	ToolsStatus       string   `json:"tools_status"`
	CPU               int32    `json:"cpu"`
	MemoryMB          int64    `json:"memory_mb"`
	DiskCapacityBytes int64    `json:"disk_capacity_bytes"`
	CommittedDiskByte int64    `json:"committed_disk_bytes"`
	Networks          []NIC    `json:"networks"`
}

type NIC struct {
	MACAddress string `json:"mac_address"`
	Network    string `json:"network"`
}

type ConsoleTicket struct {
	URL string
}

type VMListItem struct {
	VCenterID       string `json:"vcenter_id"`
	Name            string `json:"name"`
	InstanceUUID    string `json:"instance_uuid"`
	ManagedObjectID string `json:"managed_object_id"`
	InventoryPath   string `json:"inventory_path"`
	PowerState      string `json:"power_state"`
	IPAddress       string `json:"ip_address"`
}

func NewManager(configs map[string]config.VCenter, timeout time.Duration) *Manager {
	return &Manager{configs: configs, timeout: timeout, clients: make(map[string]*govmomi.Client)}
}

func (m *Manager) Check(ctx context.Context, id string) error {
	_, err := m.client(ctx, id)
	return err
}

func (m *Manager) VCenterIDs() []string {
	ids := make([]string, 0, len(m.configs))
	for id := range m.configs {
		ids = append(ids, id)
	}
	sort.Strings(ids)
	return ids
}

func (m *Manager) List(ctx context.Context, id string) ([]VMListItem, error) {
	c, err := m.client(ctx, id)
	if err != nil {
		return nil, err
	}

	viewManager := view.NewManager(c.Client)
	container, err := viewManager.CreateContainerView(
		ctx,
		c.ServiceContent.RootFolder,
		[]string{"VirtualMachine"},
		true,
	)
	if err != nil {
		return nil, fmt.Errorf("create VM inventory view: %w", err)
	}
	defer container.Destroy(ctx)

	var machines []mo.VirtualMachine
	properties := []string{"name", "config.instanceUuid", "runtime.powerState", "guest.ipAddress"}
	if err := container.Retrieve(ctx, []string{"VirtualMachine"}, properties, &machines); err != nil {
		return nil, fmt.Errorf("read VM inventory: %w", err)
	}

	items := make([]VMListItem, 0, len(machines))
	jobs := make(chan mo.VirtualMachine)
	workerCount := 12
	if len(machines) < workerCount {
		workerCount = len(machines)
	}
	var workers sync.WaitGroup
	var itemsMu sync.Mutex

	for worker := 0; worker < workerCount; worker++ {
		workers.Add(1)
		go func() {
			defer workers.Done()
			for machine := range jobs {
				path, pathErr := find.InventoryPath(ctx, c.Client, machine.Self)
				if pathErr != nil || !allowedPath(path, m.configs[id].AllowedInventoryPrefixes) {
					continue
				}

				instanceUUID := ""
				if machine.Config != nil {
					instanceUUID = strings.ToLower(strings.TrimSpace(machine.Config.InstanceUuid))
				}
				ipAddress := ""
				if machine.Guest != nil {
					ipAddress = machine.Guest.IpAddress
				}

				item := VMListItem{
					VCenterID:       id,
					Name:            machine.Name,
					InstanceUUID:    instanceUUID,
					ManagedObjectID: machine.Self.Value,
					InventoryPath:   path,
					PowerState:      string(machine.Runtime.PowerState),
					IPAddress:       ipAddress,
				}
				itemsMu.Lock()
				items = append(items, item)
				itemsMu.Unlock()
			}
		}()
	}

	for _, machine := range machines {
		select {
		case jobs <- machine:
		case <-ctx.Done():
			close(jobs)
			workers.Wait()
			return nil, fmt.Errorf("resolve VM inventory paths: %w", ctx.Err())
		}
	}
	close(jobs)
	workers.Wait()
	if err := ctx.Err(); err != nil {
		return nil, fmt.Errorf("resolve VM inventory paths: %w", err)
	}

	sort.Slice(items, func(i, j int) bool {
		return strings.ToLower(items[i].Name) < strings.ToLower(items[j].Name)
	})
	return items, nil
}

func (m *Manager) GetVM(ctx context.Context, id, instanceUUID string) (*object.VirtualMachine, string, error) {
	c, err := m.client(ctx, id)
	if err != nil {
		return nil, "", err
	}
	index := object.NewSearchIndex(c.Client)
	ref, err := index.FindByUuid(ctx, nil, instanceUUID, true, types.NewBool(true))
	if err != nil {
		return nil, "", fmt.Errorf("find VM: %w", err)
	}
	if ref == nil {
		return nil, "", errors.New("VM not found or not visible to service account")
	}
	vm, ok := ref.(*object.VirtualMachine)
	if !ok {
		vm = object.NewVirtualMachine(c.Client, ref.Reference())
	}
	path, err := find.InventoryPath(ctx, c.Client, vm.Reference())
	if err != nil {
		return nil, "", fmt.Errorf("resolve inventory path: %w", err)
	}
	if !allowedPath(path, m.configs[id].AllowedInventoryPrefixes) {
		return nil, "", errors.New("VM is outside configured inventory scope")
	}
	return vm, path, nil
}

func (m *Manager) Info(ctx context.Context, id, instanceUUID string) (*VMInfo, error) {
	vm, path, err := m.GetVM(ctx, id, instanceUUID)
	if err != nil {
		return nil, err
	}
	var p mo.VirtualMachine
	properties := []string{"name", "config.instanceUuid", "config.guestFullName", "config.hardware", "runtime.powerState", "guest", "summary.config", "summary.storage"}
	if err := vm.Properties(ctx, vm.Reference(), properties, &p); err != nil {
		return nil, fmt.Errorf("read VM properties: %w", err)
	}
	ipAddress := ""
	toolsStatus := ""
	if p.Guest != nil {
		ipAddress = p.Guest.IpAddress
		toolsStatus = string(p.Guest.ToolsRunningStatus)
	}
	info := &VMInfo{
		VCenterID: id, Name: p.Name, ManagedObjectID: vm.Reference().Value,
		InventoryPath: path, PowerState: string(p.Runtime.PowerState),
		IPAddress: ipAddress, ToolsStatus: toolsStatus,
		CPU: p.Summary.Config.NumCpu, MemoryMB: int64(p.Summary.Config.MemorySizeMB),
		CommittedDiskByte: p.Summary.Storage.Committed,
	}
	if p.Config != nil {
		info.InstanceUUID = p.Config.InstanceUuid
		info.GuestOS = p.Config.GuestFullName
		if p.Config.Hardware.Device != nil {
			for _, device := range p.Config.Hardware.Device {
				if disk, ok := device.(*types.VirtualDisk); ok {
					capacity := disk.CapacityInBytes
					if capacity <= 0 {
						capacity = disk.CapacityInKB * 1024
					}
					info.DiskCapacityBytes += capacity
				}
				if card, ok := device.(types.BaseVirtualEthernetCard); ok {
					nic := card.GetVirtualEthernetCard()
					network := ""
					if nic.DeviceInfo != nil {
						network = nic.DeviceInfo.GetDescription().Summary
					}
					info.Networks = append(info.Networks, NIC{MACAddress: nic.MacAddress, Network: network})
				}
			}
		}
	}
	return info, nil
}

func (m *Manager) Action(ctx context.Context, id, instanceUUID, action string) error {
	vm, _, err := m.GetVM(ctx, id, instanceUUID)
	if err != nil {
		return err
	}
	switch action {
	case "power_on":
		task, err := vm.PowerOn(ctx)
		if err != nil { return err }
		return task.Wait(ctx)
	case "shutdown":
		return vm.ShutdownGuest(ctx)
	case "reboot":
		return vm.RebootGuest(ctx)
	case "power_off":
		task, err := vm.PowerOff(ctx)
		if err != nil { return err }
		return task.Wait(ctx)
	case "reset":
		task, err := vm.Reset(ctx)
		if err != nil { return err }
		return task.Wait(ctx)
	default:
		return fmt.Errorf("unsupported action %q", action)
	}
}

func (m *Manager) AcquireConsole(ctx context.Context, id, instanceUUID string) (*ConsoleTicket, error) {
	vm, _, err := m.GetVM(ctx, id, instanceUUID)
	if err != nil { return nil, err }
	state, err := vm.PowerState(ctx)
	if err != nil { return nil, err }
	if state != types.VirtualMachinePowerStatePoweredOn {
		return nil, fmt.Errorf("VM is not powered on (%s)", state)
	}
	ticket, err := vm.AcquireTicket(ctx, string(types.VirtualMachineTicketTypeWebmks))
	if err != nil { return nil, fmt.Errorf("acquire WebMKS ticket: %w", err) }
	return &ConsoleTicket{URL: fmt.Sprintf("wss://%s:%d/ticket/%s", ticket.Host, ticket.Port, url.PathEscape(ticket.Ticket))}, nil
}

func (m *Manager) client(ctx context.Context, id string) (*govmomi.Client, error) {
	vc, ok := m.configs[id]
	if !ok { return nil, errors.New("unknown vCenter") }
	m.mu.Lock()
	defer m.mu.Unlock()
	if c := m.clients[id]; c != nil {
		current, sessionErr := c.SessionManager.UserSession(ctx)
		if sessionErr == nil && current != nil {
			return c, nil
		}
		c.Client.CloseIdleConnections()
		delete(m.clients, id)
	}
	u, err := url.Parse(vc.URL)
	if err != nil { return nil, fmt.Errorf("invalid vCenter URL: %w", err) }
	u.User = url.UserPassword(vc.Username, vc.Password)
	soapClient := soap.NewClient(u, false)
	soapClient.SetThumbprint(u.Host, canonicalFingerprint(vc.TLSFingerprintSHA256))
	vimClient, err := vim25.NewClient(ctx, soapClient)
	if err != nil { return nil, fmt.Errorf("connect to vCenter: %w", err) }
	c := &govmomi.Client{Client: vimClient, SessionManager: session.NewManager(vimClient)}
	if err := c.Login(ctx, u.User); err != nil {
		return nil, fmt.Errorf("login to vCenter: %w", err)
	}
	m.clients[id] = c
	return c, nil
}

func allowedPath(path string, prefixes []string) bool {
	if len(prefixes) == 0 { return false }
	for _, prefix := range prefixes {
		prefix = strings.TrimSpace(prefix)
		if prefix != "" && (path == strings.TrimSuffix(prefix, "/") || strings.HasPrefix(path, prefix)) { return true }
	}
	return false
}

func canonicalFingerprint(v string) string {
	raw := strings.ToUpper(strings.NewReplacer(":", "", "-", "", " ", "").Replace(v))
	parts := make([]string, 0, len(raw)/2)
	for i := 0; i+2 <= len(raw); i += 2 {
		parts = append(parts, raw[i:i+2])
	}
	return strings.Join(parts, ":")
}

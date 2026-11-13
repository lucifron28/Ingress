using System.Diagnostics;
using Ingress.NodeManager.Core;
using Ingress.NodeManager.Core.Execution;
using Ingress.NodeManager.Core.Networking;
using Ingress.NodeManager.Core.Security;
using Ingress.NodeManager.Core.Status;

namespace Ingress.NodeManager;

public sealed class NodeManagerForm : Form
{
    private readonly UiTheme _theme = UiTheme.Load();
    private readonly NetworkManagerClient? _manager;
    private readonly string? _startupError;
    private readonly Panel _contentHost = new();
    private readonly Panel _sidebar = new();
    private readonly Label _headerStatus = new();
    private readonly Label _lastUpdated = new();
    private readonly Label _activityLabel = new();
    private readonly ProgressBar _progress = new();
    private readonly Button _cancelButton = new();
    private Button _refreshButton = new();
    private readonly Dictionary<ManagerPage, Button> _navigation = [];
    private readonly System.Windows.Forms.Timer _refreshTimer = new() { Interval = 60_000 };
    private NodeManagerStatus _status = new();
    private NodeManagerStatus? _networkPreview;
    private ManagerPage _currentPage = ManagerPage.Dashboard;
    private int _selectedInterfaceIndex = -1;
    private string? _selectedBindAddress;
    private CancellationTokenSource? _activeOperation;
    private bool _busy;

    public NodeManagerForm()
    {
        var installation = InstallationLocator.TryLocate(AppContext.BaseDirectory);
        if (installation is null)
        {
            _startupError = "The bundled Network Manager or PowerShell could not be found. Check that the app was extracted with its scripts folder and install PowerShell 7.";
        }
        else
        {
            _manager = new NetworkManagerClient(new SystemProcessRunner(), installation);
        }

        InitializeWindow();
        BuildShell();
        ShowPage(ManagerPage.Dashboard);

        Shown += async (_, _) =>
        {
            if (_startupError is not null)
            {
                _status = new NodeManagerStatus { ErrorMessage = _startupError };
                RenderCurrentPage();
            }
            else
            {
                await RefreshStatusAsync(showProgress: true);
            }

            _refreshTimer.Start();
        };
        _refreshTimer.Tick += async (_, _) =>
        {
            if (!_busy && Visible && _manager is not null)
            {
                await RefreshStatusAsync(showProgress: false);
            }
        };
        FormClosed += (_, _) =>
        {
            _refreshTimer.Stop();
            _activeOperation?.Cancel();
            _activeOperation?.Dispose();
        };
    }

    private void InitializeWindow()
    {
        Text = "Ingress Node Manager";
        StartPosition = FormStartPosition.CenterScreen;
        var workingArea = Screen.FromPoint(Cursor.Position).WorkingArea;
        var windowWidth = Math.Max(620, Math.Min(1280, workingArea.Width - 24));
        var windowHeight = Math.Max(480, Math.Min(820, workingArea.Height - 24));
        MinimumSize = new Size(Math.Min(980, windowWidth), Math.Min(680, windowHeight));
        Size = new Size(windowWidth, windowHeight);
        AutoScaleMode = AutoScaleMode.Dpi;
        BackColor = _theme.Canvas;
        ForeColor = _theme.Ink;
        Font = new Font("Segoe UI", 9.5F, FontStyle.Regular, GraphicsUnit.Point);
        DoubleBuffered = true;
    }

    private void BuildShell()
    {
        var layout = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 2,
            RowCount = 1,
            BackColor = _theme.Canvas,
            Margin = Padding.Empty,
            Padding = Padding.Empty,
        };
        layout.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 226));
        layout.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        layout.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        Controls.Add(layout);

        _sidebar.Dock = DockStyle.Fill;
        _sidebar.BackColor = _theme.Sidebar;
        _sidebar.Padding = new Padding(18, 18, 14, 16);
        layout.Controls.Add(_sidebar, 0, 0);
        BuildSidebar();

        var main = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 1,
            RowCount = 3,
            BackColor = _theme.Canvas,
            Margin = Padding.Empty,
            Padding = Padding.Empty,
        };
        main.RowStyles.Add(new RowStyle(SizeType.Absolute, 104));
        main.RowStyles.Add(new RowStyle(SizeType.Absolute, 3));
        main.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        layout.Controls.Add(main, 1, 0);

        var header = BuildHeader();
        main.Controls.Add(header, 0, 0);
        _progress.Dock = DockStyle.Fill;
        _progress.Style = ProgressBarStyle.Marquee;
        _progress.MarqueeAnimationSpeed = 24;
        _progress.BackColor = _theme.SurfaceMuted;
        _progress.ForeColor = _theme.Accent;
        _progress.Visible = false;
        main.Controls.Add(_progress, 0, 1);

        _contentHost.Dock = DockStyle.Fill;
        _contentHost.BackColor = _theme.Canvas;
        _contentHost.Padding = new Padding(18, 14, 18, 16);
        main.Controls.Add(_contentHost, 0, 2);
    }

    private void BuildSidebar()
    {
        var stack = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 1,
            RowCount = 4,
            BackColor = _theme.Sidebar,
            Margin = Padding.Empty,
            Padding = Padding.Empty,
        };
        stack.RowStyles.Add(new RowStyle(SizeType.Absolute, 76));
        stack.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        stack.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        stack.RowStyles.Add(new RowStyle(SizeType.Absolute, 54));
        _sidebar.Controls.Add(stack);

        var brand = new Panel { Dock = DockStyle.Fill, BackColor = _theme.Sidebar, Margin = Padding.Empty };
        var mark = new PictureBox
        {
            Image = LoadBrandMark(),
            SizeMode = PictureBoxSizeMode.Zoom,
            BackColor = Color.Transparent,
            Size = new Size(42, 42),
            Location = new Point(0, 4),
        };
        var brandName = new Label
        {
            Text = "INGRESS",
            Font = new Font("Segoe UI", 13, FontStyle.Bold),
            ForeColor = _theme.Accent,
            AutoSize = true,
            Location = new Point(54, 6),
        };
        var brandSub = new Label
        {
            Text = "NODE MANAGER",
            Font = new Font("Segoe UI", 8.2F, FontStyle.Bold),
            ForeColor = _theme.Warning,
            AutoSize = true,
            Location = new Point(55, 30),
        };
        brand.Controls.Add(mark);
        brand.Controls.Add(brandName);
        brand.Controls.Add(brandSub);
        stack.Controls.Add(brand, 0, 0);

        var section = new Label
        {
            Text = "EVENT NODE",
            Dock = DockStyle.Fill,
            ForeColor = _theme.Warning,
            Font = new Font("Segoe UI", 8.2F, FontStyle.Bold),
            TextAlign = ContentAlignment.MiddleLeft,
            Padding = new Padding(2, 0, 0, 8),
        };
        stack.Controls.Add(section, 0, 1);

        var nav = new FlowLayoutPanel
        {
            Dock = DockStyle.Fill,
            FlowDirection = FlowDirection.TopDown,
            WrapContents = false,
            AutoScroll = true,
            BackColor = _theme.Sidebar,
            Margin = Padding.Empty,
            Padding = Padding.Empty,
        };
        stack.Controls.Add(nav, 0, 2);
        AddNavigation(nav, ManagerPage.Dashboard, "⌂", "Dashboard");
        AddNavigation(nav, ManagerPage.Network, "◉", "Network Manager");
        AddNavigation(nav, ManagerPage.Controls, "▣", "Event Node Controls");
        AddNavigation(nav, ManagerPage.Pairing, "◇", "Staff & Pairing");
        AddNavigation(nav, ManagerPage.Diagnostics, "≡", "Diagnostics");

        var footer = new Label
        {
            Text = "Local operator tool\nPowerShell CLI remains available",
            Dock = DockStyle.Fill,
            ForeColor = _theme.Muted,
            Font = new Font("Segoe UI", 8.2F),
            TextAlign = ContentAlignment.MiddleLeft,
            Padding = new Padding(2, 0, 0, 0),
        };
        stack.Controls.Add(footer, 0, 3);
    }

    private static Image LoadBrandMark()
    {
        var markPath = Path.Combine(AppContext.BaseDirectory, "assets", "ingress-mark.png");
        using var source = Image.FromFile(markPath);
        return new Bitmap(source);
    }

    private void AddNavigation(Control parent, ManagerPage page, string glyph, string text)
    {
        var button = new Button
        {
            Text = $"{glyph}    {text}",
            TextAlign = ContentAlignment.MiddleLeft,
            FlatStyle = FlatStyle.Flat,
            Font = new Font("Segoe UI", 9.4F, FontStyle.Bold),
            ForeColor = _theme.SidebarText,
            BackColor = _theme.Sidebar,
            Size = new Size(192, 46),
            Margin = new Padding(0, 3, 0, 4),
            Padding = new Padding(10, 0, 0, 0),
            Cursor = Cursors.Hand,
            AccessibleName = text,
        };
        button.FlatAppearance.BorderSize = 0;
        button.FlatAppearance.MouseOverBackColor = _theme.SurfaceMuted;
        button.MouseEnter += (_, _) => button.ForeColor = _theme.AccentHover;
        button.MouseLeave += (_, _) => button.ForeColor = page == _currentPage ? _theme.Accent : _theme.SidebarText;
        button.Click += (_, _) => ShowPage(page);
        _navigation.Add(page, button);
        parent.Controls.Add(button);
    }

    private Control BuildHeader()
    {
        var panel = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            BackColor = _theme.Surface,
            Padding = new Padding(24, 6, 22, 4),
            Margin = Padding.Empty,
            ColumnCount = 2,
            RowCount = 2,
        };
        panel.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 62));
        panel.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 38));
        panel.RowStyles.Add(new RowStyle(SizeType.Absolute, 52));
        panel.RowStyles.Add(new RowStyle(SizeType.Percent, 100));

        var heading = new FlowLayoutPanel
        {
            Dock = DockStyle.Fill,
            FlowDirection = FlowDirection.TopDown,
            WrapContents = false,
            BackColor = Color.Transparent,
            Margin = Padding.Empty,
        };
        var eyebrow = MakeLabel("INGRESS  /  EVENT NODE", 8.2F, FontStyle.Bold, _theme.Muted);
        eyebrow.Margin = Padding.Empty;
        var title = MakeLabel("Ingress Node Manager", 17, FontStyle.Bold, _theme.Ink);
        title.Margin = new Padding(0, 1, 0, 0);
        heading.Controls.Add(eyebrow);
        heading.Controls.Add(title);
        panel.Controls.Add(heading, 0, 0);

        _lastUpdated.Text = "Status not checked";
        _lastUpdated.AutoSize = true;
        _lastUpdated.ForeColor = _theme.Muted;
        _lastUpdated.Font = new Font("Segoe UI", 8.5F);
        _lastUpdated.Margin = Padding.Empty;

        var statusContainer = new FlowLayoutPanel
        {
            Dock = DockStyle.Fill,
            FlowDirection = FlowDirection.RightToLeft,
            WrapContents = false,
            BackColor = Color.Transparent,
            Margin = Padding.Empty,
            Padding = new Padding(0, 3, 0, 3),
        };
        _headerStatus.AutoSize = true;
        _headerStatus.TextAlign = ContentAlignment.MiddleCenter;
        _headerStatus.Font = new Font("Segoe UI", 8.8F, FontStyle.Bold);
        _headerStatus.Padding = new Padding(10, 5, 10, 5);
        _headerStatus.Margin = Padding.Empty;
        statusContainer.Controls.Add(_headerStatus);
        panel.Controls.Add(statusContainer, 1, 0);

        _activityLabel.AutoSize = true;
        _activityLabel.Font = new Font("Segoe UI", 8.4F, FontStyle.Bold);
        _activityLabel.ForeColor = _theme.Accent;
        _activityLabel.Margin = Padding.Empty;
        _activityLabel.Visible = false;
        var meta = new FlowLayoutPanel
        {
            Dock = DockStyle.Fill,
            FlowDirection = FlowDirection.LeftToRight,
            WrapContents = false,
            BackColor = Color.Transparent,
            Margin = Padding.Empty,
        };
        meta.Controls.Add(_lastUpdated);
        meta.Controls.Add(_activityLabel);
        panel.Controls.Add(meta, 0, 1);

        _refreshButton = MakeButton("Refresh", primary: true);
        _refreshButton.Size = new Size(100, 36);
        _refreshButton.Margin = new Padding(0, 1, 8, 1);
        _refreshButton.Click += async (_, _) => await RefreshStatusAsync(showProgress: true);

        _cancelButton.Text = "Cancel";
        _cancelButton.Size = new Size(86, 36);
        _cancelButton.Margin = new Padding(0, 1, 8, 1);
        _cancelButton.Visible = false;
        _cancelButton.Click += (_, _) => _activeOperation?.Cancel();
        StyleSecondaryButton(_cancelButton);
        var actions = new FlowLayoutPanel
        {
            Dock = DockStyle.Fill,
            FlowDirection = FlowDirection.RightToLeft,
            WrapContents = false,
            BackColor = Color.Transparent,
            Margin = Padding.Empty,
            Padding = Padding.Empty,
        };
        actions.Controls.Add(_refreshButton);
        actions.Controls.Add(_cancelButton);
        panel.Controls.Add(actions, 1, 1);
        return panel;
    }

    private void ShowPage(ManagerPage page)
    {
        _currentPage = page;
        foreach (var pair in _navigation)
        {
            var selected = pair.Key == page;
            pair.Value.BackColor = selected ? _theme.SurfaceMuted : _theme.Sidebar;
            pair.Value.ForeColor = selected ? _theme.Accent : _theme.SidebarText;
            pair.Value.FlatAppearance.BorderSize = selected ? 1 : 0;
            pair.Value.FlatAppearance.BorderColor = _theme.Warning;
        }

        RenderCurrentPage();
    }

    private void RenderCurrentPage()
    {
        _contentHost.SuspendLayout();
        _contentHost.Controls.Clear();
        var page = _currentPage switch
        {
            ManagerPage.Dashboard => BuildDashboardPage(),
            ManagerPage.Network => BuildNetworkPage(),
            ManagerPage.Controls => BuildControlsPage(),
            ManagerPage.Pairing => BuildPairingPage(),
            _ => BuildDiagnosticsPage(),
        };
        page.Dock = DockStyle.Fill;
        _contentHost.Controls.Add(page);
        _contentHost.ResumeLayout(performLayout: true);
        UpdateHeaderStatus();
    }

    private Control BuildDashboardPage()
    {
        var root = CreatePageRoot();
        AddPageHeader(root, "OVERVIEW", "Ingress at this venue", "Start the Node, pair the scanner, and check local status from one place.");
        if (_status.SchemaVersion == 0 && _status.ErrorMessage is null)
        {
            var loading = CreateCard(14);
            loading.Controls.Add(MakeLabel("Checking local services", 11, FontStyle.Bold, _theme.Ink));
            loading.Controls.Add(MakeLabel("Ingress is checking the current network, Docker, PostgreSQL, and Event Node. This can take a few seconds.", 9.5F, FontStyle.Regular, _theme.Muted));
            root.Controls.Add(loading);
            return root;
        }
        AddStartupError(root);

        var hero = CreateCard(14);
        var top = new TableLayoutPanel { Dock = DockStyle.Top, ColumnCount = 2, RowCount = 1, AutoSize = true, BackColor = Color.Transparent };
        top.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 65));
        top.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 35));
        var active = MakeLabel(_status.NetworkMode, 15, FontStyle.Bold, _theme.Ink);
        active.AutoSize = true;
        var apiAddress = _status.NodeUrl is "Unknown" or "unavailable" ? _status.NodeUrl : _status.NodeUrl.TrimEnd('/') + "/api/v1";
        var nodeUrl = MakeLabel(apiAddress, 10, FontStyle.Regular, _theme.Muted);
        nodeUrl.AutoSize = true;
        var left = new FlowLayoutPanel { FlowDirection = FlowDirection.TopDown, AutoSize = true, WrapContents = false, BackColor = Color.Transparent, Margin = Padding.Empty };
        left.Controls.Add(MakeLabel("SCANNER API ADDRESS", 8, FontStyle.Bold, _theme.Muted));
        left.Controls.Add(active);
        left.Controls.Add(nodeUrl);
        top.Controls.Add(left, 0, 0);

        var detectedAddresses = _status.Adapters.Where(IsUsableLanAdapter)
            .Select(adapter => $"{adapter.Name}  {adapter.Address}")
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .Take(4)
            .ToArray();
        var right = new FlowLayoutPanel { FlowDirection = FlowDirection.TopDown, AutoSize = true, WrapContents = false, BackColor = Color.Transparent, Margin = Padding.Empty };
        right.Controls.Add(MakeLabel("AVAILABLE LAN ADDRESSES", 8, FontStyle.Bold, _theme.Muted));
        foreach (var address in detectedAddresses) right.Controls.Add(MakeLabel(address, 9.2F, FontStyle.Regular, _theme.Ink));
        if (detectedAddresses.Length == 0) right.Controls.Add(MakeLabel("No LAN address detected", 9.2F, FontStyle.Regular, _theme.Warning));
        top.Controls.Add(right, 1, 0);
        hero.Controls.Add(top);
        root.Controls.Add(hero);

        var currentBindAddressDetected = _status.Adapters.Any(adapter =>
            IsUsableLanAdapter(adapter) && string.Equals(adapter.Address, _status.CurrentBindAddress, StringComparison.Ordinal));
        if (!currentBindAddressDetected && GetSelectedLanAdapter() is not null)
        {
            var message = _status.NodeHealthy
                ? $"This PC's network address changed. The Node is still bound to {_status.CurrentBindAddress}. Choose Network to preview the new address; active admissions will block a restart."
                : "This PC's network address changed. Start Server will bind the Node to the selected address and update the scanner URL.";
            root.Controls.Add(MakeLabel(message, 9.2F, FontStyle.Bold, _theme.Warning));
        }
        else if (!currentBindAddressDetected && _status.Adapters.Count(IsUsableLanAdapter) > 1)
        {
            root.Controls.Add(MakeLabel("Several private networks are active. Choose Network and select the Wi-Fi or hotspot shared with the scanner.", 9.2F, FontStyle.Bold, _theme.Warning));
        }

        if (_status.NodeHealthy)
        {
            root.Controls.Add(MakeLabel("The local Event Node is running.", 9.2F, FontStyle.Bold, _theme.Success));
            if (!_status.LanProbeReachable)
            {
                root.Controls.Add(MakeLabel("The Node is running, but its LAN HTTP address is not reachable yet. Start Server to apply the selected LAN settings.", 9.2F, FontStyle.Bold, _theme.Warning));
            }
        }
        else if (_status.SchemaVersion > 0 && !_status.CanApplyNetworkChange)
        {
            root.Controls.Add(MakeLabel("Choose Start Server to review network checks and blockers.", 9.2F, FontStyle.Bold, _theme.Warning));
        }

        var actions = new FlowLayoutPanel { AutoSize = true, Dock = DockStyle.Top, WrapContents = true, BackColor = Color.Transparent, Margin = new Padding(0, 2, 0, 10) };
        var refresh = MakeButton("Refresh status", primary: true);
        refresh.Click += async (_, _) => await RefreshStatusAsync(showProgress: true);
        var chooseNetwork = MakeButton("Choose network", primary: false);
        chooseNetwork.Click += (_, _) => ShowPage(ManagerPage.Network);
        var copyScannerAddress = MakeButton("Copy scanner address", primary: false);
        copyScannerAddress.Click += (_, _) => CopyScannerAddress();
        var start = MakeButton("Start Server", primary: false);
        start.Enabled = CanStartNode() && !_busy;
        start.AccessibleDescription = _status.NodeHealthy && _status.LanProbeReachable && _status.OperatorPortIsolated
            ? "Disabled because the LAN demo server is already reachable."
            : "Starts or switches the Node to the selected LAN only after the database, migrations, network, firewall, admission, and operator checks pass.";
        start.Click += async (_, _) => await StartNodeAsync();
        var operatorConsole = MakeButton("Open Operator Console", primary: false);
        operatorConsole.Enabled = CanOpenOperatorConsole() && !_busy;
        operatorConsole.Click += (_, _) => OpenOperatorConsole("/");
        var diagnostics = MakeButton("View Diagnostics", primary: false);
        diagnostics.Click += (_, _) => ShowPage(ManagerPage.Diagnostics);
        actions.Controls.Add(refresh);
        actions.Controls.Add(chooseNetwork);
        actions.Controls.Add(copyScannerAddress);
        actions.Controls.Add(start);
        actions.Controls.Add(operatorConsole);
        actions.Controls.Add(diagnostics);
        root.Controls.Add(actions);

        var indicators = new[]
        {
            _status.GetIndicator("Network Mode"),
            _status.GetIndicator("Docker / WSL2"),
            _status.GetIndicator("Event Node"),
            _status.GetIndicator("PostgreSQL"),
            _status.GetIndicator("Database Migrations"),
            _status.GetIndicator("Scheduler"),
            _status.GetIndicator("LAN Transport"),
            _status.GetIndicator("Windows Firewall"),
            _status.GetIndicator("Operator Port"),
            _status.GetIndicator("Admission Safety"),
            _status.GetIndicator("Android Scanner"),
        };
        root.Controls.Add(CreateIndicatorGrid(indicators));
        root.Controls.Add(CreatePhysicalTestNote());
        var warnings = CreateWarningsPanel();
        if (warnings is not null) root.Controls.Add(warnings);
        return root;
    }

    private Control BuildNetworkPage()
    {
        var root = CreatePageRoot();
        AddPageHeader(root, "NETWORK MANAGER", "Choose the active LAN", "The server address follows the selected adapter. Select a different adapter after changing hotspots.");
        AddStartupError(root);

        var selection = CreateCard(14);
        selection.Controls.Add(MakeLabel("Network adapter", 11, FontStyle.Bold, _theme.Ink));
        var choices = new ComboBox
        {
            DropDownStyle = ComboBoxStyle.DropDownList,
            Width = Math.Min(580, GetPageTextWidth(40)),
            Font = new Font("Segoe UI", 10F),
            Margin = new Padding(0, 9, 0, 2),
            FormattingEnabled = true,
        };
        choices.Format += (_, args) =>
        {
            if (args.ListItem is NetworkAdapterStatus adapter)
            {
                args.Value = $"{adapter.Name}  ·  {adapter.Address}/{adapter.PrefixLength}  ·  {adapter.NetworkCategory}";
            }
        };
        var eligibleAdapters = _status.Adapters.Where(IsUsableLanAdapter).ToArray();
        foreach (var adapter in eligibleAdapters) choices.Items.Add(adapter);
        var preferred = GetSelectedLanAdapter();
        var preferredIndex = preferred is null ? -1 : choices.Items.IndexOf(preferred);
        if (preferredIndex >= 0) choices.SelectedIndex = preferredIndex;
        else if (choices.Items.Count == 1) choices.SelectedIndex = 0;
        choices.SelectedIndexChanged += (_, _) =>
        {
            if (choices.SelectedItem is NetworkAdapterStatus adapter)
            {
                _selectedInterfaceIndex = adapter.InterfaceIndex;
                _selectedBindAddress = adapter.Address;
                _networkPreview = null;
            }
        };
        selection.Controls.Add(choices);
        if (eligibleAdapters.Length == 0)
        {
            selection.Controls.Add(MakeLabel("No active private IPv4 network was detected. Connect this PC to the same Wi-Fi or hotspot as the scanner, then refresh.", 9.5F, FontStyle.Regular, _theme.Warning));
        }
        else if (preferred is null && eligibleAdapters.Length > 1)
        {
            selection.Controls.Add(MakeLabel("Several private networks are active. Select the Wi-Fi or hotspot shared with the scanner before starting the server.", 9.5F, FontStyle.Regular, _theme.Warning));
        }
        root.Controls.Add(selection);

        var actions = new FlowLayoutPanel { AutoSize = true, Dock = DockStyle.Top, WrapContents = true, BackColor = Color.Transparent, Margin = new Padding(0, 0, 0, 12) };
        var previewButton = MakeButton("Preview changes", primary: true);
        previewButton.Enabled = _manager is not null && !_busy && choices.SelectedItem is NetworkAdapterStatus;
        previewButton.Click += async (_, _) => await PreviewNetworkChangeAsync();
        actions.Controls.Add(previewButton);
        if (_networkPreview is not null)
        {
            if (!_networkPreview.FirewallAllowed)
            {
                var firewallButton = MakeButton("Configure Windows Firewall", primary: false);
                firewallButton.Enabled = !_busy && choices.SelectedItem is NetworkAdapterStatus;
                firewallButton.Click += async (_, _) => await ConfigureLanFirewallAsync();
                actions.Controls.Add(firewallButton);
            }
            var applyButton = MakeButton("Start on this network", primary: false);
            applyButton.Enabled = _networkPreview.CanApplyNetworkChange && !_busy;
            applyButton.Click += async (_, _) => await ApplyNetworkChangeAsync();
            actions.Controls.Add(applyButton);
        }
        root.Controls.Add(actions);

        if (_networkPreview is not null)
        {
            root.Controls.Add(BuildNetworkPreviewPanel(_networkPreview));
        }

        root.Controls.Add(CreateAdapterPanel(_status.Adapters));
        return root;
    }

    private Control BuildControlsPage()
    {
        var root = CreatePageRoot();
        AddPageHeader(root, "NODE CONTROLS", "Operate the local Node", "Actions call the existing PowerShell Network Manager. Database and scheduler containers are never deleted or recreated here.");
        AddStartupError(root);

        var actions = CreateCard(14);
        actions.Controls.Add(MakeLabel("Event Node", 12, FontStyle.Bold, _theme.Ink));
        actions.Controls.Add(MakeLabel($"Current state: {_status.NodeStatus}", 10, FontStyle.Regular, _theme.Muted));
        var buttons = new FlowLayoutPanel { AutoSize = true, Dock = DockStyle.Top, WrapContents = true, BackColor = Color.Transparent, Margin = new Padding(0, 10, 0, 0) };

        var start = MakeButton("Start / Resume", primary: true);
        start.Enabled = CanStartNode() && !_busy;
        start.Click += async (_, _) => await StartNodeAsync();
        buttons.Controls.Add(start);

        var refresh = MakeButton("Refresh Container Status", primary: false);
        refresh.Click += async (_, _) => await RefreshStatusAsync(showProgress: true);
        buttons.Controls.Add(refresh);

        var openConsole = MakeButton("Open Operator Console", primary: false);
        openConsole.Enabled = CanOpenOperatorConsole();
        openConsole.Click += (_, _) => OpenOperatorConsole("/");
        buttons.Controls.Add(openConsole);

        var logs = MakeButton("View Recent Logs", primary: false);
        logs.Enabled = _manager is not null && !_busy;
        logs.Click += async (_, _) => await ShowRecentLogsAsync();
        buttons.Controls.Add(logs);

        var scheduler = MakeButton("View Scheduler Status", primary: false);
        scheduler.Click += (_, _) => ShowSchedulerDetails();
        buttons.Controls.Add(scheduler);
        actions.Controls.Add(buttons);
        root.Controls.Add(actions);

        var sync = CreateCard(14);
        sync.Controls.Add(MakeLabel("Closeout and pending synchronization", 11, FontStyle.Bold, _theme.Ink));
        sync.Controls.Add(MakeLabel(_status.PendingSyncStateKnown
            ? $"Known pending records: {_status.PendingSyncRecords?.ToString() ?? "unknown"}"
            : "Pending synchronization is unknown; Safe Stop is disabled.", 10, FontStyle.Regular, _theme.Muted));
        var safeStop = MakeButton("Safe Stop", primary: false);
        safeStop.Enabled = _status.CanSafelyStop && !_busy;
        safeStop.Click += async (_, _) => await SafeStopNodeAsync();
        sync.Controls.Add(safeStop);
        if (_status.SafeStopBlockers.Length > 0)
        {
            sync.Controls.Add(BuildBulletPanel("Safe Stop is blocked", _status.SafeStopBlockers, _theme.Danger));
        }
        root.Controls.Add(sync);

        var schedulerPanel = CreateCard(14);
        var schedulerIndicator = _status.GetIndicator("Scheduler");
        schedulerPanel.Controls.Add(CreateStatusCard(schedulerIndicator));
        schedulerPanel.Controls.Add(MakeLabel(_status.SchedulerDetail, 9.2F, FontStyle.Regular, _theme.Muted));
        root.Controls.Add(schedulerPanel);
        return root;
    }

    private Control BuildPairingPage()
    {
        var root = CreatePageRoot();
        AddPageHeader(root, "STAFF & SCANNER PAIRING", "Pair scanners through the Node console", "Credentials and one-use QR codes stay in the existing authenticated Laravel operator workflow.");
        AddStartupError(root);

        var intro = CreateCard(16);
        intro.Controls.Add(MakeLabel("Guided pairing", 13, FontStyle.Bold, _theme.Ink));
        intro.Controls.Add(MakeLabel("The Event Node owns event assignment, gate authorization, credential creation, QR expiry, and cancellation. This desktop manager does not request or store staff passwords or pairing tokens.", 10, FontStyle.Regular, _theme.Muted));
        intro.Controls.Add(CreateStatusCard(_status.GetIndicator("Operator Port")));
        var open = MakeButton("Open Staff & Scanner Console", primary: true);
        open.Enabled = CanOpenOperatorConsole();
        open.Click += (_, _) => OpenOperatorConsole("/staff");
        intro.Controls.Add(open);
        root.Controls.Add(intro);

        var steps = CreateCard(16);
        steps.Controls.Add(MakeLabel("What to do in the console", 11, FontStyle.Bold, _theme.Ink));
        var stepRows = new[]
        {
            ("01", "Choose the imported event and assigned staff member."),
            ("02", "Confirm the staff member's assigned gate and local credential status."),
            ("03", "Provision a credential only when none exists, after the console's explicit confirmation."),
            ("04", "Generate the one-use pairing QR. The current Node QR expires after three minutes."),
            ("05", "Show it only to that staff member. Cancel an unused code or generate a replacement in the console."),
        };
        foreach (var (number, description) in stepRows)
        {
            steps.Controls.Add(CreateNumberedStep(number, description));
        }
        steps.Controls.Add(MakeLabel("Phone connectivity and installed CA trust are not visible from Windows. Test pairing on each physical scanner.", 9.5F, FontStyle.Bold, _theme.Warning));
        root.Controls.Add(steps);
        return root;
    }

    private Control BuildDiagnosticsPage()
    {
        var root = CreatePageRoot();
        AddPageHeader(root, "DIAGNOSTICS", "Safe system diagnostics", "Only verified, non-secret status fields appear here. No database URL, keys, credentials, or pairing data are included.");
        AddStartupError(root);

        var actions = new FlowLayoutPanel { AutoSize = true, Dock = DockStyle.Top, WrapContents = true, BackColor = Color.Transparent };
        var copy = MakeButton("Copy Safe Diagnostics", primary: true);
        copy.Click += (_, _) => CopyDiagnostics();
        actions.Controls.Add(copy);
        var refresh = MakeButton("Refresh", primary: false);
        refresh.Click += async (_, _) => await RefreshStatusAsync(showProgress: true);
        actions.Controls.Add(refresh);
        var open = MakeButton("Open Operator Console", primary: false);
        open.Enabled = CanOpenOperatorConsole();
        open.Click += (_, _) => OpenOperatorConsole("/");
        actions.Controls.Add(open);
        root.Controls.Add(actions);

        var allIndicators = new[]
        {
            _status.GetIndicator("Docker / WSL2"), _status.GetIndicator("Event Node"), _status.GetIndicator("PostgreSQL"),
            _status.GetIndicator("Database Migrations"), _status.GetIndicator("Scheduler"), _status.GetIndicator("LAN Transport"), _status.GetIndicator("Windows Firewall"),
            _status.GetIndicator("Operator Port"), _status.GetIndicator("Admission Safety"),
            _status.GetIndicator("Android Scanner"),
        };
        root.Controls.Add(CreateIndicatorGrid(allIndicators));
        root.Controls.Add(CreateAdapterPanel(_status.Adapters));
        if (_status.Blockers.Length > 0) root.Controls.Add(BuildBulletPanel("Network change blockers", _status.Blockers, _theme.Danger));
        if (_status.SafeStopBlockers.Length > 0) root.Controls.Add(BuildBulletPanel("Safe Stop blockers", _status.SafeStopBlockers, _theme.Warning));

        var report = CreateCard(12);
        report.Controls.Add(MakeLabel("Copy preview", 10, FontStyle.Bold, _theme.Ink));
        var text = new TextBox
        {
            Multiline = true,
            ReadOnly = true,
            ScrollBars = ScrollBars.Vertical,
            WordWrap = true,
            BorderStyle = BorderStyle.FixedSingle,
            BackColor = _theme.SurfaceMuted,
            ForeColor = _theme.Ink,
            Font = new Font("Consolas", 8.7F),
            Height = 250,
            Dock = DockStyle.Top,
            Text = SafeDiagnosticsBuilder.Build(_status),
        };
        report.Controls.Add(text);
        root.Controls.Add(report);
        return root;
    }

    private Control BuildNetworkPreviewPanel(NodeManagerStatus preview)
    {
        var panel = CreateCard(14);
        panel.Controls.Add(MakeLabel("Preview result", 12, FontStyle.Bold, _theme.Ink));
        panel.Controls.Add(MakeLabel($"Network: {preview.NetworkMode}", 10, FontStyle.Regular, _theme.Muted));
        panel.Controls.Add(MakeLabel($"Node address: {preview.CurrentBindAddress}  →  {preview.TargetBindAddress}", 10, FontStyle.Bold, _theme.Ink));
        panel.Controls.Add(MakeLabel($"Scanner API address: {preview.NodeUrl.TrimEnd('/')}/api/v1", 10, FontStyle.Bold, _theme.Ink));
        panel.Controls.Add(MakeLabel("Enter this address in the scanner, or scan a one-use pairing QR from Staff & Pairing.", 9.5F, FontStyle.Regular, _theme.Muted));
        panel.Controls.Add(MakeLabel("HTTP is enabled only for this private LAN demo. Do not expose this server to the internet.", 9.5F, FontStyle.Regular, _theme.Warning));
        panel.Controls.Add(MakeLabel($"Firewall: {preview.FirewallStatus}", 9.5F, FontStyle.Regular, _theme.Muted));
        panel.Controls.Add(MakeLabel($"Admission safety: {preview.AdmissionStatus}", 9.5F, FontStyle.Regular, _theme.Muted));
        if (preview.Blockers.Length > 0)
        {
            panel.Controls.Add(BuildBulletPanel("Apply is blocked", preview.Blockers, _theme.Danger));
        }
        else
        {
            panel.Controls.Add(MakeLabel("Preview passed. The manager will recheck safety immediately before applying.", 9.5F, FontStyle.Bold, _theme.Success));
        }
        if (preview.Warnings.Length > 0) panel.Controls.Add(BuildBulletPanel("Warnings", preview.Warnings, _theme.Warning));
        return panel;
    }

    private Control CreateIndicatorGrid(IEnumerable<HealthIndicator> indicators)
    {
        var values = indicators.ToArray();
        var grid = new TableLayoutPanel
        {
            Dock = DockStyle.Top,
            AutoSize = true,
            AutoSizeMode = AutoSizeMode.GrowAndShrink,
            ColumnCount = 1,
            RowCount = 0,
            BackColor = Color.Transparent,
            Margin = new Padding(0, 0, 0, 8),
        };
        var cards = values.Select(CreateStatusCard).ToArray();
        var configuredColumns = 0;
        void ArrangeColumns(int width)
        {
            var columns = width >= 840 ? 2 : 1;
            if (columns == configuredColumns) return;

            grid.SuspendLayout();
            grid.Controls.Clear();
            grid.ColumnStyles.Clear();
            grid.RowStyles.Clear();
            grid.ColumnCount = columns;
            grid.RowCount = (int)Math.Ceiling(cards.Length / (double)columns);
            for (var column = 0; column < columns; column++)
            {
                grid.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100F / columns));
            }
            for (var row = 0; row < grid.RowCount; row++)
            {
                grid.RowStyles.Add(new RowStyle(SizeType.AutoSize));
            }
            for (var index = 0; index < cards.Length; index++)
            {
                cards[index].Dock = DockStyle.Fill;
                grid.Controls.Add(cards[index], index % columns, index / columns);
            }
            configuredColumns = columns;
            grid.ResumeLayout(performLayout: true);
        }

        ArrangeColumns(GetPageTextWidth(40));
        grid.SizeChanged += (_, _) => ArrangeColumns(grid.ClientSize.Width);
        return grid;
    }

    private Control CreateStatusCard(HealthIndicator indicator)
    {
        var panel = new TableLayoutPanel
        {
            ColumnCount = 3,
            RowCount = 2,
            AutoSize = true,
            AutoSizeMode = AutoSizeMode.GrowAndShrink,
            MinimumSize = new Size(0, 104),
            BackColor = _theme.Surface,
            Padding = new Padding(12),
            Margin = new Padding(6, 5, 6, 5),
        };
        panel.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 4));
        panel.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        panel.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        panel.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        panel.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        var accent = new Panel
        {
            Dock = DockStyle.Fill,
            BackColor = _theme.ForLevel(indicator.Level),
            Margin = Padding.Empty,
        };
        var title = MakeLabel(indicator.Name, 10.1F, FontStyle.Bold, _theme.Ink);
        title.Margin = Padding.Empty;
        var badge = new Label
        {
            Text = indicator.Level.ToString().ToUpperInvariant(),
            AutoSize = true,
            TextAlign = ContentAlignment.MiddleCenter,
            Font = new Font("Segoe UI", 7.7F, FontStyle.Bold),
            ForeColor = _theme.ForLevel(indicator.Level),
            BackColor = _theme.SurfaceForLevel(indicator.Level),
            Padding = new Padding(7, 3, 7, 3),
            Margin = new Padding(8, 0, 0, 0),
        };
        var detail = MakeLabel(indicator.Detail, 9.1F, FontStyle.Regular, _theme.Muted);
        detail.Margin = new Padding(0, 5, 0, 0);
        void ResizeCardContent()
        {
            var contentWidth = Math.Max(1, panel.ClientSize.Width - panel.Padding.Horizontal - 4);
            title.MaximumSize = new Size(Math.Max(1, contentWidth - badge.PreferredSize.Width - 8), 0);
            detail.MaximumSize = new Size(contentWidth, 0);
        }
        panel.Controls.Add(accent, 0, 0);
        panel.SetRowSpan(accent, 2);
        panel.Controls.Add(title, 1, 0);
        panel.Controls.Add(badge, 2, 0);
        panel.Controls.Add(detail, 1, 1);
        panel.SetColumnSpan(detail, 2);
        panel.SizeChanged += (_, _) => ResizeCardContent();
        ResizeCardContent();
        return panel;
    }

    private Control CreateAdapterPanel(NetworkAdapterStatus[] adapters)
    {
        var panel = CreateCard(12);
        panel.Controls.Add(MakeLabel("Detected adapters and addresses", 11, FontStyle.Bold, _theme.Ink));
        if (adapters.Length == 0)
        {
            panel.Controls.Add(MakeLabel("No adapter data has been verified yet.", 9.5F, FontStyle.Regular, _theme.Muted));
            return panel;
        }

        var list = new DataGridView
        {
            Dock = DockStyle.Top,
            Height = Math.Min(230, 40 + adapters.Length * 34),
            AutoGenerateColumns = false,
            ReadOnly = true,
            AllowUserToAddRows = false,
            AllowUserToDeleteRows = false,
            AllowUserToResizeRows = false,
            RowHeadersVisible = false,
            AutoSizeColumnsMode = DataGridViewAutoSizeColumnsMode.Fill,
            SelectionMode = DataGridViewSelectionMode.FullRowSelect,
            MultiSelect = false,
            BackgroundColor = _theme.Surface,
            GridColor = _theme.Border,
            BorderStyle = BorderStyle.None,
            EnableHeadersVisualStyles = false,
        };
        list.ColumnHeadersDefaultCellStyle.BackColor = _theme.SurfaceMuted;
        list.ColumnHeadersDefaultCellStyle.ForeColor = _theme.Muted;
        list.ColumnHeadersDefaultCellStyle.SelectionBackColor = _theme.SurfaceMuted;
        list.ColumnHeadersDefaultCellStyle.SelectionForeColor = _theme.Accent;
        list.ColumnHeadersDefaultCellStyle.Font = new Font("Segoe UI", 8.5F, FontStyle.Bold);
        list.DefaultCellStyle.BackColor = _theme.Surface;
        list.DefaultCellStyle.ForeColor = _theme.Ink;
        list.DefaultCellStyle.SelectionBackColor = _theme.SurfaceMuted;
        list.DefaultCellStyle.SelectionForeColor = _theme.Ink;
        list.Columns.Add(new DataGridViewTextBoxColumn { HeaderText = "MODE", DataPropertyName = nameof(NetworkAdapterStatus.Mode), FillWeight = 24 });
        list.Columns.Add(new DataGridViewTextBoxColumn { HeaderText = "ADAPTER", DataPropertyName = nameof(NetworkAdapterStatus.Name), FillWeight = 28 });
        list.Columns.Add(new DataGridViewTextBoxColumn { HeaderText = "IPv4", DataPropertyName = nameof(NetworkAdapterStatus.Address), FillWeight = 23 });
        list.Columns.Add(new DataGridViewTextBoxColumn { HeaderText = "NETWORK", DataPropertyName = nameof(NetworkAdapterStatus.NetworkCategory), FillWeight = 16 });
        list.Columns.Add(new DataGridViewCheckBoxColumn { HeaderText = "UP", DataPropertyName = nameof(NetworkAdapterStatus.IsUp), FillWeight = 9 });
        list.DataSource = adapters.ToList();
        panel.Controls.Add(list);
        return panel;
    }

    private Control CreateProfilePanel(SavedNetworkProfile[] profiles, bool known)
    {
        var panel = CreateCard(12);
        panel.Controls.Add(MakeLabel("Saved network profiles", 11, FontStyle.Bold, _theme.Ink));
        if (!known)
        {
            panel.Controls.Add(MakeLabel("Profile discovery is unknown. The app did not read profile secrets; refresh with the Event Node's WSL distribution available.", 9.3F, FontStyle.Regular, _theme.Muted));
            return panel;
        }

        foreach (var profile in profiles)
        {
            var state = profile.IsConfigured ? "Configured" : "Not configured";
            var color = profile.IsConfigured ? _theme.Success : _theme.Unknown;
            panel.Controls.Add(MakeLabel($"{profile.Name}  ·  {state}  ·  {profile.Address}", 9.5F, FontStyle.Bold, color));
            if (profile.IsConfigured)
            {
                panel.Controls.Add(MakeLabel($"Saved Node URL: {profile.AppUrl}", 9, FontStyle.Regular, _theme.Muted));
            }
        }
        if (profiles.Length == 0)
        {
            panel.Controls.Add(MakeLabel("No saved profiles were found.", 9.3F, FontStyle.Regular, _theme.Muted));
        }
        return panel;
    }

    private Control? CreateWarningsPanel()
    {
        if (_status.Warnings.Length == 0)
        {
            return null;
        }

        return BuildBulletPanel("Notes and limits", _status.Warnings, _theme.Warning);
    }

    private Control CreatePhysicalTestNote()
    {
        var note = CreateCard(12);
        note.BackColor = _theme.SurfaceForLevel(HealthLevel.Unknown);
        note.Controls.Add(MakeLabel("Physical device testing required", 10.2F, FontStyle.Bold, _theme.Unknown));
        note.Controls.Add(MakeLabel("Desktop checks cannot prove phone-to-laptop routing, camera permission, pairing, or a real ticket scan. Test the scanner on the demo network before presenting.", 9.2F, FontStyle.Regular, _theme.Muted));
        return note;
    }

    private Control BuildBulletPanel(string title, IEnumerable<string> items, Color color)
    {
        var array = items.ToArray();
        return array.Length == 0 ? CreateBulletPanel(title, ["None"], _theme.Success) : CreateBulletPanel(title, array, color);
    }

    private Control CreateBulletPanel(string title, IEnumerable<string> items, Color color)
    {
        var panel = CreateCard(12);
        panel.Controls.Add(MakeLabel(title, 10.5F, FontStyle.Bold, color));
        foreach (var item in items)
        {
            var label = MakeLabel($"•  {SecretRedactor.Redact(item)}", 9.1F, FontStyle.Regular, _theme.Ink);
            label.AutoSize = true;
            label.MaximumSize = new Size(GetPageTextWidth(40), 0);
            panel.Controls.Add(label);
        }
        return panel;
    }

    private Control CreateNumberedStep(string number, string description)
    {
        var row = new TableLayoutPanel { Dock = DockStyle.Top, AutoSize = true, ColumnCount = 2, RowCount = 1, BackColor = Color.Transparent, Margin = new Padding(0, 5, 0, 8) };
        row.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 46));
        row.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        var badge = new Label
        {
            Text = number,
            ForeColor = _theme.Accent,
            BackColor = _theme.SurfaceMuted,
            Font = new Font("Segoe UI", 9, FontStyle.Bold),
            TextAlign = ContentAlignment.MiddleCenter,
            Dock = DockStyle.Top,
            Height = 30,
        };
        row.Controls.Add(badge, 0, 0);
        var detail = MakeLabel(description, 9.6F, FontStyle.Regular, _theme.Ink);
        detail.AutoSize = true;
        detail.MaximumSize = new Size(GetPageTextWidth(96), 0);
        row.Controls.Add(detail, 1, 0);
        return row;
    }

    private void AddPageHeader(Control root, string eyebrow, string title, string subtitle)
    {
        var header = new FlowLayoutPanel { FlowDirection = FlowDirection.TopDown, WrapContents = false, AutoSize = true, Dock = DockStyle.Top, BackColor = Color.Transparent, Margin = new Padding(0, 0, 0, 10) };
        header.Controls.Add(MakeLabel(eyebrow, 8.1F, FontStyle.Bold, _theme.Accent));
        header.Controls.Add(MakeLabel(title, 21, FontStyle.Bold, _theme.Ink));
        var line = MakeLabel(subtitle, 9.5F, FontStyle.Regular, _theme.Muted);
        line.AutoSize = true;
        line.MaximumSize = new Size(GetPageTextWidth(32), 0);
        header.Controls.Add(line);
        root.Controls.Add(header);
    }

    private void AddStartupError(Control root)
    {
        if (string.IsNullOrWhiteSpace(_status.ErrorMessage))
        {
            return;
        }

        var error = CreateCard(12);
        error.BackColor = _theme.SurfaceForLevel(HealthLevel.Blocked);
        error.Controls.Add(MakeLabel("Status unavailable", 10.2F, FontStyle.Bold, _theme.Danger));
        var message = MakeLabel(SecretRedactor.Redact(_status.ErrorMessage), 9.4F, FontStyle.Regular, _theme.Ink);
        message.AutoSize = true;
        message.MaximumSize = new Size(GetPageTextWidth(40), 0);
        error.Controls.Add(message);
        error.Controls.Add(MakeLabel("Check PowerShell 7, WSL2, Docker Desktop, and the Event Node's Compose containers, then refresh. No unknown check is treated as healthy.", 9, FontStyle.Regular, _theme.Muted));
        root.Controls.Add(error);
    }

    private FlowLayoutPanel CreatePageRoot()
    {
        var root = new FlowLayoutPanel
        {
            FlowDirection = FlowDirection.TopDown,
            WrapContents = false,
            AutoScroll = true,
            BackColor = Color.Transparent,
            Padding = Padding.Empty,
            Margin = Padding.Empty,
            Dock = DockStyle.Fill,
        };
        root.ControlAdded += (_, eventArgs) =>
        {
            if (eventArgs.Control is { } item)
            {
                SizePageItem(root, item);
            }
        };
        root.SizeChanged += (_, _) =>
        {
            foreach (Control child in root.Controls)
            {
                SizePageItem(root, child);
            }
        };
        return root;
    }

    private FlowLayoutPanel CreateCard(int padding)
    {
        var card = new FlowLayoutPanel
        {
            FlowDirection = FlowDirection.TopDown,
            WrapContents = false,
            BackColor = _theme.Surface,
            Padding = new Padding(padding),
            Margin = new Padding(0, 0, 0, 12),
            Dock = DockStyle.Top,
            AutoSize = true,
            AutoSizeMode = AutoSizeMode.GrowAndShrink,
        };
        card.ControlAdded += (_, eventArgs) =>
        {
            if (eventArgs.Control is { } item)
            {
                SizeCardItem(card, item);
            }
        };
        card.SizeChanged += (_, _) =>
        {
            foreach (Control child in card.Controls)
            {
                SizeCardItem(card, child);
            }
        };
        return card;
    }

    private int GetPageTextWidth(int inset) => Math.Max(1, _contentHost.ClientSize.Width - _contentHost.Padding.Horizontal - inset);

    private void SizePageItem(FlowLayoutPanel page, Control item)
    {
        var scrollBarWidth = page.VerticalScroll.Visible ? SystemInformation.VerticalScrollBarWidth : 0;
        var width = Math.Max(1, page.ClientSize.Width - scrollBarWidth - item.Margin.Horizontal - 6);
        SizeFlowItem(item, width);
    }

    private void SizeCardItem(FlowLayoutPanel card, Control item)
    {
        var scrollBarWidth = card.VerticalScroll.Visible ? SystemInformation.VerticalScrollBarWidth : 0;
        var width = Math.Max(1, card.ClientSize.Width - card.Padding.Horizontal - scrollBarWidth - item.Margin.Horizontal - 4);
        SizeFlowItem(item, width);
    }

    private static void SizeFlowItem(Control item, int width)
    {
        width = Math.Max(1, width);
        if (item is Button)
        {
            return;
        }

        item.MaximumSize = new Size(width, 0);
        if (item is FlowLayoutPanel flow)
        {
            flow.Width = width;
            return;
        }

        if (item is Label label)
        {
            if (label.AutoSize)
            {
                label.MaximumSize = new Size(width, 0);
            }
            else
            {
                label.Width = width;
            }

            return;
        }

        item.Width = width;
    }

    private Label MakeLabel(string text, float size, FontStyle style, Color color)
    {
        return new Label
        {
            Text = text,
            Font = new Font("Segoe UI", size, style, GraphicsUnit.Point),
            ForeColor = color,
            BackColor = Color.Transparent,
            Margin = new Padding(0, 0, 0, 5),
            AutoSize = true,
        };
    }

    private Button MakeButton(string text, bool primary)
    {
        var button = new Button
        {
            Text = text,
            AutoSize = true,
            MinimumSize = new Size(122, 38),
            Padding = new Padding(12, 4, 12, 4),
            Margin = new Padding(0, 4, 10, 6),
            Cursor = Cursors.Hand,
            Font = new Font("Segoe UI", 9.2F, FontStyle.Bold),
        };
        if (primary)
        {
            button.FlatStyle = FlatStyle.Flat;
            button.FlatAppearance.BorderSize = 0;
            button.BackColor = _theme.Accent;
            button.ForeColor = Color.White;
            button.FlatAppearance.MouseOverBackColor = _theme.AccentHover;
            button.FlatAppearance.MouseDownBackColor = _theme.AccentHover;
        }
        else
        {
            StyleSecondaryButton(button);
        }
        return button;
    }

    private void StyleSecondaryButton(Button button)
    {
        button.FlatStyle = FlatStyle.Flat;
        button.FlatAppearance.BorderColor = _theme.Border;
        button.FlatAppearance.BorderSize = 1;
        button.BackColor = _theme.Surface;
        button.ForeColor = _theme.Ink;
        button.FlatAppearance.MouseOverBackColor = _theme.SurfaceMuted;
        button.Font = new Font("Segoe UI", 9.2F, FontStyle.Bold);
        button.Cursor = Cursors.Hand;
    }

    private async Task RefreshStatusAsync(bool showProgress)
    {
        if (_manager is null)
        {
            _status = new NodeManagerStatus { ErrorMessage = _startupError ?? "The Network Manager is unavailable." };
            RenderCurrentPage();
            return;
        }

        if (showProgress)
        {
            await RunOperationAsync("Refreshing system status", async token =>
            {
                await RefreshStatusCoreAsync(token);
            }, refreshAfter: false);
        }
        else
        {
            await RefreshStatusCoreAsync(CancellationToken.None);
        }
    }

    private async Task RefreshStatusCoreAsync(CancellationToken cancellationToken)
    {
        if (_manager is null)
        {
            _status = new NodeManagerStatus { ErrorMessage = _startupError };
            RenderCurrentPage();
            return;
        }

        try
        {
            _status = await _manager.GetStatusAsync(cancellationToken);
            _status = _status with { ErrorMessage = null };
            _lastUpdated.Text = $"Updated {DateTime.Now:HH:mm:ss}";
        }
        catch (OperationCanceledException)
        {
            throw;
        }
        catch (Exception exception)
        {
            _status = new NodeManagerStatus
            {
                ErrorMessage = SecretRedactor.Redact(exception.Message),
            };
            _lastUpdated.Text = "Status not verified";
        }

        SyncSelectedLanAdapter();
        RenderCurrentPage();
    }

    private async Task RunOperationAsync(string description, Func<CancellationToken, Task> operation, bool refreshAfter = true)
    {
        if (_busy)
        {
            return;
        }

        if (_manager is null)
        {
            MessageBox.Show(this, _startupError ?? "The manager is not available.", "Manager unavailable", MessageBoxButtons.OK, MessageBoxIcon.Error);
            return;
        }

        _busy = true;
        using var cancellation = new CancellationTokenSource();
        _activeOperation = cancellation;
        _progress.Visible = true;
        _activityLabel.Text = description;
        _activityLabel.Visible = true;
        _cancelButton.Visible = true;
        _refreshButton.Enabled = false;
        RenderCurrentPage();
        try
        {
            await operation(cancellation.Token);
            if (refreshAfter)
            {
                await RefreshStatusCoreAsync(cancellation.Token);
            }
        }
        catch (OperationCanceledException)
        {
            _activityLabel.Text = "Operation cancelled. Refreshing status…";
            if (refreshAfter && _manager is not null)
            {
                await RefreshStatusCoreAsync(CancellationToken.None);
            }
        }
        catch (Exception exception)
        {
            var safeMessage = SecretRedactor.Redact(exception.Message);
            MessageBox.Show(this, safeMessage, "Operation could not be completed", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            if (refreshAfter && _manager is not null)
            {
                await RefreshStatusCoreAsync(CancellationToken.None);
            }
        }
        finally
        {
            _activeOperation = null;
            _busy = false;
            _progress.Visible = false;
            _activityLabel.Visible = false;
            _cancelButton.Visible = false;
            _refreshButton.Enabled = true;
            RenderCurrentPage();
        }
    }

    private async Task PreviewNetworkChangeAsync()
    {
        var adapter = GetSelectedLanAdapter();
        if (adapter is null)
        {
            MessageBox.Show(this, "Connect this PC to the same Wi-Fi or hotspot as the scanner, then refresh the network list.",
                "No LAN network selected", MessageBoxButtons.OK, MessageBoxIcon.Information);
            return;
        }
        _networkPreview = null;
        await RunOperationAsync("Checking the selected LAN and Node safety", async token =>
        {
            _networkPreview = await _manager!.PreviewLanDemoNetworkChangeAsync(adapter, token);
        }, refreshAfter: false);
        if (_networkPreview is not null) _status = _networkPreview;
        RenderCurrentPage();
    }

    private async Task ApplyNetworkChangeAsync()
    {
        if (_networkPreview is null || !_networkPreview.CanApplyNetworkChange)
        {
            return;
        }

        var adapter = GetSelectedLanAdapter();
        if (adapter is null) return;
        var message = $"Start the Event Node on this network?\n\n"
            + $"Node address: {_networkPreview.CurrentBindAddress} → {_networkPreview.TargetBindAddress}\n"
            + $"Scanner URL: {_networkPreview.NodeUrl}\n\n"
            + "Ingress will reuse the existing database and volumes. The manager rechecks migrations, admission state, LAN firewall scope, and the laptop-only operator port before starting the Node.";
        if (MessageBox.Show(this, message, "Start Event Node", MessageBoxButtons.YesNo, MessageBoxIcon.Question) != DialogResult.Yes)
        {
            return;
        }

        await RunOperationAsync("Starting the Event Node on the selected LAN", async token =>
        {
            await _manager!.ApplyLanDemoNetworkChangeAsync(adapter, confirmationAccepted: true, token);
            _networkPreview = null;
        });
    }

    private async Task StartNodeAsync()
    {
        if (!CanStartNode())
        {
            return;
        }

        var adapter = GetSelectedLanAdapter();
        if (adapter is null)
        {
            ShowPage(ManagerPage.Network);
            return;
        }

        NodeManagerStatus? preview = null;
        await RunOperationAsync("Checking the selected LAN and Node safety", async token =>
        {
            preview = await _manager!.PreviewLanDemoNetworkChangeAsync(adapter, token);
        }, refreshAfter: false);
        if (preview is null) return;
        _status = preview;
        RenderCurrentPage();
        if (!preview.CanApplyNetworkChange)
        {
            _networkPreview = preview;
            MessageBox.Show(this, string.Join(Environment.NewLine, preview.Blockers), "Server cannot start yet", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            if (!preview.FirewallAllowed) ShowPage(ManagerPage.Network);
            return;
        }

        var message = $"Start the Event Node on {adapter.Name} ({adapter.Address})?\n\n"
            + $"Scanner API: http://{adapter.Address}:{preview.LanHttpPort}/api/v1\n\n"
            + "The manager rechecks database health, migrations, admission state, firewall scope, and the loopback-only operator port. It keeps PostgreSQL and its existing data.";
        if (MessageBox.Show(this, message, "Start Event Node", MessageBoxButtons.YesNo, MessageBoxIcon.Question) != DialogResult.Yes) return;
        await RunOperationAsync("Starting the Event Node", async token =>
        {
            await _manager!.StartLanDemoNodeAsync(adapter, confirmationAccepted: true, token);
        });
    }

    private async Task SafeStopNodeAsync()
    {
        if (!_status.CanSafelyStop)
        {
            return;
        }

        NodeManagerStatus? preview = null;
        await RunOperationAsync("Rechecking admission and synchronization state", async token =>
        {
            preview = await _manager!.PreviewSafeStopNodeAsync(token);
        }, refreshAfter: false);
        if (preview is null || !preview.CanSafelyStop)
        {
            if (preview is not null) { _status = preview; }
            RenderCurrentPage();
            return;
        }

        _status = preview;
        RenderCurrentPage();

        var message = $"Safe-stop checks passed:\n• Admissions possible: no\n• Pending cloud-sync records: {preview.PendingSyncRecords}\n• Event authority: verified\n• Operator port: loopback-only\n\nStop only ingress-node? PostgreSQL, scheduler, and the persistent database volume will remain running.";
        if (MessageBox.Show(this, message, "Confirm Safe Stop", MessageBoxButtons.YesNo, MessageBoxIcon.Warning) != DialogResult.Yes)
        {
            return;
        }

        await RunOperationAsync("Stopping only ingress-node after a fresh safety check", async token =>
        {
            await _manager!.SafeStopNodeAsync(confirmationAccepted: true, token);
        });
    }

    private async Task ConfigureLanFirewallAsync()
    {
        var adapter = GetSelectedLanAdapter();
        if (adapter is null) return;
        await RunOperationAsync("Adding a LAN-scoped firewall rule (Windows may ask for administrator approval)", async token =>
        {
            await _manager!.ConfigureLanFirewallAsync(adapter, _status.LanHttpPort, token);
        });
    }

    private async Task ShowRecentLogsAsync()
    {
        string[]? lines = null;
        await RunOperationAsync("Reading the last 150 Node log lines", async token =>
        {
            lines = await _manager!.GetRecentLogsAsync(token);
        }, refreshAfter: false);
        if (lines is null)
        {
            return;
        }

        using var viewer = new LogViewerForm(SafeLogFormatter.Format(lines), _theme);
        viewer.ShowDialog(this);
    }

    private void OpenOperatorConsole(string path)
    {
        if (!CanOpenOperatorConsole())
        {
            MessageBox.Show(this,
                "The operator port is not verified as loopback-only. Refresh diagnostics and resolve the port binding before opening it.",
                "Operator console blocked", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            return;
        }

        var port = _status.OperatorPort > 0 ? _status.OperatorPort : 8444;
        var normalizedPath = path.StartsWith('/') ? path : "/" + path;
        var scheme = _status.NodeUrl.StartsWith("http://", StringComparison.OrdinalIgnoreCase) ? Uri.UriSchemeHttp : Uri.UriSchemeHttps;
        var uri = new UriBuilder(scheme, "127.0.0.1", port, "/node/operator" + normalizedPath).Uri;
        try
        {
            Process.Start(new ProcessStartInfo(uri.AbsoluteUri) { UseShellExecute = true });
        }
        catch (Exception exception) when (exception is InvalidOperationException or System.ComponentModel.Win32Exception)
        {
            MessageBox.Show(this, "Windows could not open the default browser. Copy this local operator address:\n" + uri,
                "Browser unavailable", MessageBoxButtons.OK, MessageBoxIcon.Information);
        }
    }

    private void CopyDiagnostics()
    {
        var text = SafeDiagnosticsBuilder.Build(_status);
        try
        {
            Clipboard.SetText(text);
            MessageBox.Show(this, "Safe diagnostics copied. The report excludes secrets, pairing codes, and ticket credentials.",
                "Copied", MessageBoxButtons.OK, MessageBoxIcon.Information);
        }
        catch (System.Runtime.InteropServices.ExternalException)
        {
            MessageBox.Show(this, text, "Safe diagnostics", MessageBoxButtons.OK, MessageBoxIcon.Information);
        }
    }

    private void CopyScannerAddress()
    {
        if (_status.NodeUrl is "Unknown" or "unavailable" || !_status.NodeUrl.StartsWith("http://", StringComparison.OrdinalIgnoreCase))
        {
            MessageBox.Show(this, "Start the server on a detected LAN first.", "Scanner address unavailable", MessageBoxButtons.OK, MessageBoxIcon.Information);
            return;
        }
        try
        {
            Clipboard.SetText(_status.NodeUrl.TrimEnd('/') + "/api/v1");
            MessageBox.Show(this, "The scanner API address was copied.", "Copied", MessageBoxButtons.OK, MessageBoxIcon.Information);
        }
        catch (System.Runtime.InteropServices.ExternalException)
        {
            MessageBox.Show(this, _status.NodeUrl.TrimEnd('/') + "/api/v1", "Scanner API address", MessageBoxButtons.OK, MessageBoxIcon.Information);
        }
    }

    private void ShowSchedulerDetails()
    {
        var item = _status.GetIndicator("Scheduler");
        var message = $"Status: {item.Level}\n{item.Detail}\n\n{_status.SchedulerDetail}\n\n"
            + "The current Event Node does not expose a scheduler heartbeat. A running container is therefore a warning, not a verified healthy scheduler.";
        MessageBox.Show(this, message, "Scheduler status", MessageBoxButtons.OK, MessageBoxIcon.Information);
    }

    private bool CanStartNode() => _manager is not null && !_busy && GetSelectedLanAdapter() is not null
        && (!_status.NodeHealthy || !_status.LanProbeReachable || !_status.OperatorPortIsolated);

    private bool CanOpenOperatorConsole() => _status.NodeStateKnown && _status.NodeHealthy
        && _status.OperatorPortStateKnown && _status.OperatorPortIsolated;

    private static bool IsUsableLanAdapter(NetworkAdapterStatus adapter)
    {
        if (!adapter.IsUp || adapter.InterfaceIndex < 1 || !System.Net.IPAddress.TryParse(adapter.Address, out var ip)
            || ip.AddressFamily != System.Net.Sockets.AddressFamily.InterNetwork) return false;
        var bytes = ip.GetAddressBytes();
        return bytes[0] == 10 || (bytes[0] == 172 && bytes[1] is >= 16 and <= 31) || (bytes[0] == 192 && bytes[1] == 168);
    }

    private NetworkAdapterStatus? GetSelectedLanAdapter()
    {
        var selected = _status.Adapters.FirstOrDefault(adapter => IsUsableLanAdapter(adapter)
            && adapter.InterfaceIndex == _selectedInterfaceIndex
            && string.Equals(adapter.Address, _selectedBindAddress, StringComparison.Ordinal));
        if (selected is not null) return selected;
        var current = _status.Adapters.FirstOrDefault(adapter => IsUsableLanAdapter(adapter)
            && string.Equals(adapter.Address, _status.CurrentBindAddress, StringComparison.Ordinal));
        if (current is not null) return current;
        var candidates = _status.Adapters.Where(IsUsableLanAdapter).ToArray();
        return candidates.Length == 1 ? candidates[0] : null;
    }

    private void SyncSelectedLanAdapter()
    {
        var selected = GetSelectedLanAdapter();
        if (selected is null) return;
        _selectedInterfaceIndex = selected.InterfaceIndex;
        _selectedBindAddress = selected.Address;
    }

    private void UpdateHeaderStatus()
    {
        var node = _status.GetIndicator("Event Node");
        var text = _status.ErrorMessage is not null ? "STATUS UNKNOWN" : node.Level switch
        {
            HealthLevel.Healthy => "NODE HEALTHY",
            HealthLevel.Warning => "REVIEW STATUS",
            HealthLevel.Blocked => "ACTION NEEDED",
            _ => "STATUS UNKNOWN",
        };
        _headerStatus.Text = text;
        _headerStatus.ForeColor = _theme.ForLevel(_status.ErrorMessage is not null ? HealthLevel.Unknown : node.Level);
        _headerStatus.BackColor = _theme.SurfaceForLevel(_status.ErrorMessage is not null ? HealthLevel.Unknown : node.Level);
        _activityLabel.Visible = _busy;
        _lastUpdated.Visible = !_busy;
    }

    private enum ManagerPage
    {
        Dashboard,
        Network,
        Controls,
        Pairing,
        Diagnostics,
    }
}

internal sealed class LogViewerForm : Form
{
    public LogViewerForm(string safeLogText, UiTheme theme)
    {
        Text = "Recent Event Node Logs · Safe View";
        StartPosition = FormStartPosition.CenterParent;
        MinimumSize = new Size(760, 480);
        Size = new Size(900, 600);
        AutoScaleMode = AutoScaleMode.Dpi;
        BackColor = theme.Surface;
        ForeColor = theme.Ink;
        Font = new Font("Segoe UI", 9F);

        var info = new Label
        {
            Text = "Sensitive ticket, pairing, verification, password, and token lines are omitted or redacted.",
            Dock = DockStyle.Top,
            Height = 38,
            TextAlign = ContentAlignment.MiddleLeft,
            ForeColor = theme.Muted,
            Padding = new Padding(12, 0, 12, 0),
        };
        var logs = new RichTextBox
        {
            Dock = DockStyle.Fill,
            ReadOnly = true,
            DetectUrls = false,
            WordWrap = false,
            BackColor = theme.Surface,
            ForeColor = theme.Ink,
            Font = new Font("Consolas", 9F),
            BorderStyle = BorderStyle.None,
            Text = safeLogText,
        };
        var close = new Button { Text = "Close", Dock = DockStyle.Bottom, Height = 42 };
        close.Click += (_, _) => Close();
        Controls.Add(logs);
        Controls.Add(info);
        Controls.Add(close);
    }
}

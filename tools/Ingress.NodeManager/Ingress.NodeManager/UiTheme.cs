namespace Ingress.NodeManager;

internal sealed record UiTheme(
    Color Canvas,
    Color Surface,
    Color SurfaceMuted,
    Color Ink,
    Color Muted,
    Color Border,
    Color Sidebar,
    Color SidebarText,
    Color Accent,
    Color AccentHover,
    Color Success,
    Color Warning,
    Color Danger,
    Color Unknown)
{
    public static UiTheme Load() => new(
        Canvas: Color.FromArgb(247, 243, 237),
        Surface: Color.FromArgb(255, 252, 247),
        SurfaceMuted: Color.FromArgb(240, 230, 219),
        Ink: Color.FromArgb(48, 38, 41),
        Muted: Color.FromArgb(112, 96, 94),
        Border: Color.FromArgb(225, 212, 199),
        Sidebar: Color.FromArgb(243, 234, 225),
        SidebarText: Color.FromArgb(82, 29, 40),
        Accent: Color.FromArgb(128, 0, 0),
        AccentHover: Color.FromArgb(91, 0, 0),
        Success: Color.FromArgb(39, 122, 82),
        Warning: Color.FromArgb(150, 101, 0),
        Danger: Color.FromArgb(176, 48, 55),
        Unknown: Color.FromArgb(112, 106, 101));

    public Color ForLevel(Core.Status.HealthLevel level) => level switch
    {
        Core.Status.HealthLevel.Healthy => Success,
        Core.Status.HealthLevel.Warning => Warning,
        Core.Status.HealthLevel.Blocked => Danger,
        _ => Unknown,
    };

    public Color SurfaceForLevel(Core.Status.HealthLevel level) => level switch
    {
        Core.Status.HealthLevel.Healthy => Color.FromArgb(233, 247, 239),
        Core.Status.HealthLevel.Warning => Color.FromArgb(255, 247, 225),
        Core.Status.HealthLevel.Blocked => Color.FromArgb(255, 238, 237),
        _ => Color.FromArgb(242, 237, 231),
    };
}

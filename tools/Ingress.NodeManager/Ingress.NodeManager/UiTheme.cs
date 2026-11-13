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
    Color SidebarMuted,
    Color Accent,
    Color AccentText,
    Color AccentHover,
    Color Success,
    Color Warning,
    Color Danger,
    Color Unknown)
{
    public static UiTheme Load() => new(
        Color.FromArgb(247, 242, 236), Color.FromArgb(255, 252, 247), Color.FromArgb(240, 230, 218),
        Color.FromArgb(42, 31, 31), Color.FromArgb(112, 99, 96), Color.FromArgb(227, 216, 205),
        Color.FromArgb(244, 236, 227), Color.FromArgb(92, 23, 34), Color.FromArgb(125, 92, 25),
        Color.FromArgb(116, 29, 43), Color.FromArgb(122, 86, 17), Color.FromArgb(91, 0, 0),
        Color.FromArgb(33, 122, 75), Color.FromArgb(156, 105, 18), Color.FromArgb(180, 43, 52),
        Color.FromArgb(116, 110, 108));

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

using Microsoft.Win32;

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
    Color Unknown,
    bool IsDark)
{
    public static UiTheme Load()
    {
        var dark = false;
        try
        {
            using var key = Registry.CurrentUser.OpenSubKey("Software\\Microsoft\\Windows\\CurrentVersion\\Themes\\Personalize");
            dark = key?.GetValue("AppsUseLightTheme") is int value && value == 0;
        }
        catch (UnauthorizedAccessException)
        {
        }
        catch (System.Security.SecurityException)
        {
        }

        return dark
            ? new UiTheme(
                Color.FromArgb(31, 27, 26), Color.FromArgb(43, 37, 36), Color.FromArgb(57, 49, 47),
                Color.FromArgb(249, 246, 244), Color.FromArgb(198, 187, 180), Color.FromArgb(78, 67, 64),
                Color.FromArgb(27, 23, 22), Color.FromArgb(248, 244, 241), Color.FromArgb(226, 190, 112),
                Color.FromArgb(116, 29, 43), Color.FromArgb(240, 207, 129), Color.FromArgb(142, 39, 56),
                Color.FromArgb(74, 222, 128), Color.FromArgb(251, 191, 36), Color.FromArgb(248, 113, 113),
                Color.FromArgb(190, 181, 174), true)
            : new UiTheme(
                Color.FromArgb(250, 247, 244), Color.White, Color.FromArgb(245, 237, 226),
                Color.FromArgb(36, 29, 29), Color.FromArgb(109, 98, 96), Color.FromArgb(231, 223, 218),
                Color.FromArgb(91, 0, 0), Color.White, Color.FromArgb(226, 190, 112),
                Color.FromArgb(116, 29, 43), Color.FromArgb(122, 86, 17), Color.FromArgb(91, 0, 0),
                Color.FromArgb(33, 122, 75), Color.FromArgb(166, 111, 22), Color.FromArgb(180, 43, 52),
                Color.FromArgb(116, 110, 108), false);
    }

    public Color ForLevel(Core.Status.HealthLevel level) => level switch
    {
        Core.Status.HealthLevel.Healthy => Success,
        Core.Status.HealthLevel.Warning => Warning,
        Core.Status.HealthLevel.Blocked => Danger,
        _ => Unknown,
    };

    public Color SurfaceForLevel(Core.Status.HealthLevel level) => level switch
    {
        Core.Status.HealthLevel.Healthy => IsDark ? Color.FromArgb(24, 61, 48) : Color.FromArgb(233, 247, 239),
        Core.Status.HealthLevel.Warning => IsDark ? Color.FromArgb(73, 57, 29) : Color.FromArgb(255, 247, 225),
        Core.Status.HealthLevel.Blocked => IsDark ? Color.FromArgb(74, 39, 45) : Color.FromArgb(255, 238, 237),
        _ => IsDark ? Color.FromArgb(52, 46, 43) : Color.FromArgb(242, 237, 231),
    };
}

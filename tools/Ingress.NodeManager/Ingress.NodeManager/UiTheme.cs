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
    Color Accent,
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
                Color.FromArgb(22, 27, 37), Color.FromArgb(31, 38, 51), Color.FromArgb(39, 47, 62),
                Color.FromArgb(234, 239, 247), Color.FromArgb(163, 174, 190), Color.FromArgb(57, 66, 82),
                Color.FromArgb(17, 25, 40), Color.FromArgb(226, 232, 240), Color.FromArgb(96, 165, 250),
                Color.FromArgb(125, 185, 255), Color.FromArgb(74, 222, 128), Color.FromArgb(251, 191, 36),
                Color.FromArgb(248, 113, 113), Color.FromArgb(148, 163, 184), true)
            : new UiTheme(
                Color.FromArgb(245, 247, 251), Color.White, Color.FromArgb(239, 243, 248),
                Color.FromArgb(24, 34, 54), Color.FromArgb(98, 111, 132), Color.FromArgb(224, 230, 239),
                Color.FromArgb(17, 33, 56), Color.FromArgb(226, 232, 240), Color.FromArgb(42, 97, 184),
                Color.FromArgb(31, 78, 150), Color.FromArgb(33, 126, 83), Color.FromArgb(168, 105, 16),
                Color.FromArgb(181, 55, 61), Color.FromArgb(111, 124, 145), false);
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
        _ => IsDark ? Color.FromArgb(47, 55, 69) : Color.FromArgb(237, 241, 247),
    };
}

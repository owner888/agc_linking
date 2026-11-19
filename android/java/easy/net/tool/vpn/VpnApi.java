package easy.net.tool.vpn;

import android.content.Context;
import android.content.Intent;
import java.io.File;
import java.io.IOException;

/**
 * What the app's PHP calls over JNI, all static, the activity as the Context. State and traffic come from files
 * the :service process keeps (VpnSession), so nothing here holds a variable that two processes would disagree on.
 */
public final class VpnApi {
    private VpnApi() {}

    /**
     * Writes the Xray config and starts the tunnel; the system's permission dialog comes first when the app has
     * none. The result is not known here: ask state().
     *
     * @param apiPort    port of the config's stats API when detailedTraffic
     * @param detailedTraffic also count what went by the proxy and what by the direct outbound; Xray is asked about it
     *                   every second (a new process each time), so it is off unless the person asks
     * @param appMode    which apps go through it: "all", "only" the packages of apps, or all "except" them
     * @param apps       comma-separated package names, for "only" and "except"
     * @param title      notification title, empty for the app's name
     * @param text       notification text, empty for none
     * @param stopLabel  label of the notification's disconnect button, empty for no button
     */
    public static void start(Context context, String configJson, int socksPort, int apiPort, boolean detailedTraffic, String appMode, String apps, String title, String text, String stopLabel)
            throws IOException {
        LastSession.save(context, configJson, socksPort, apiPort, detailedTraffic, appMode, apps, title, text, stopLabel);
        context.startActivity(LastSession.intent(context, new Intent(context, VpnLauncherActivity.class)));
    }

    /** Whether a connection has been made before, so that the tile, the boot and a broadcast can make it again. */
    public static boolean hasLastSession(Context context) {
        return LastSession.exists(context);
    }

    /** Forgets what the last connection was (its proxy's secrets with it): the proxy was removed. */
    public static void forgetLastSession(Context context) {
        LastSession.forget(context);
    }

    /** Turns an automation on or off: "autostart" (connect when the phone has started) or "broadcast" (take the app's broadcasts). */
    public static void setOption(Context context, String name, boolean on) {
        Options.set(context, name, on);
    }

    /** The folder Xray reads geoip.dat and geosite.dat from; the app's updates of them are written there. */
    public static String assetDir(Context context) {
        File dir = new File(context.getFilesDir(), "xray-assets");
        dir.mkdirs();
        return dir.getPath();
    }

    /** The apps with an icon on the launcher, but this one, one per line: "package", a tab, the name; by name. */
    public static String installedApps(Context context) {
        android.content.pm.PackageManager pm = context.getPackageManager();
        Intent main = new Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_LAUNCHER);
        java.util.TreeMap<String, String> byName = new java.util.TreeMap<>(String.CASE_INSENSITIVE_ORDER);
        for (android.content.pm.ResolveInfo info : pm.queryIntentActivities(main, 0)) {
            String pkg = info.activityInfo.packageName;
            if (!pkg.equals(context.getPackageName())) {
                String label = info.loadLabel(pm).toString().replace('\t', ' ').replace('\n', ' ');
                byName.put(label + "\u0000" + pkg, pkg + "\t" + label);
            }
        }
        return String.join("\n", byName.values());
    }

    public static void stop(Context context) {
        context.startService(new Intent(context, LinkingVpnService.class).setAction(LinkingVpnService.ACTION_STOP));
    }

    /** stopped, connecting, connected or error. */
    public static String state(Context context) {
        return VpnSession.state(context);
    }

    /** The message of the last error, empty when there is none. */
    public static String error(Context context) {
        return VpnSession.error(context);
    }

    /** "uploaded downloaded", with detailed traffic "uploaded downloaded proxyUp proxyDown directUp directDown",
     * in bytes since the tunnel came up; empty when none is up. */
    public static String traffic(Context context) {
        return VpnSession.traffic(context);
    }
}

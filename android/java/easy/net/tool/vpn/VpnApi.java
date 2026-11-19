package easy.net.tool.vpn;

import android.content.Context;
import android.content.Intent;
import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.nio.charset.StandardCharsets;

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
     * @param title      notification title, empty for the app's name
     * @param text       notification text, empty for none
     * @param stopLabel  label of the notification's disconnect button, empty for no button
     */
    public static void start(Context context, String configJson, int socksPort, int apiPort, boolean detailedTraffic, String title, String text, String stopLabel)
            throws IOException {
        File config = new File(context.getFilesDir(), "xray.json");
        try (FileOutputStream out = new FileOutputStream(config)) {
            out.write(configJson.getBytes(StandardCharsets.UTF_8));
        }
        Intent launch = new Intent(context, VpnLauncherActivity.class)
            .putExtra(LinkingVpnService.EXTRA_CONFIG, config.getPath())
            .putExtra(LinkingVpnService.EXTRA_SOCKS_PORT, socksPort)
            .putExtra(LinkingVpnService.EXTRA_API_PORT, apiPort)
            .putExtra(LinkingVpnService.EXTRA_DETAILED_TRAFFIC, detailedTraffic)
            .putExtra(LinkingVpnService.EXTRA_TITLE, title)
            .putExtra(LinkingVpnService.EXTRA_TEXT, text)
            .putExtra(LinkingVpnService.EXTRA_STOP_LABEL, stopLabel);
        context.startActivity(launch);
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

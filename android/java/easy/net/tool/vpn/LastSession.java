package easy.net.tool.vpn;

import android.content.Context;
import android.content.Intent;
import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.nio.charset.StandardCharsets;
import org.json.JSONException;
import org.json.JSONObject;

/**
 * What the last connection was made with (the Xray config, the apps, the notification's texts), kept in the app's
 * folder, so that the tile, the boot and a broadcast can make it again with the UI's process gone. It holds the proxy's
 * secrets, as the app's own store of profiles does, and is as private as it: the app's folder.
 */
final class LastSession {
    private static final String FILE = "last-session.json";

    private LastSession() {}

    static void save(Context context, String configJson, int socksPort, int apiPort, boolean detailedTraffic, String appMode,
                     String apps, String title, String text, String stopLabel) throws IOException {
        try {
            JSONObject o = new JSONObject()
                .put("config", configJson)
                .put(LinkingVpnService.EXTRA_SOCKS_PORT, socksPort)
                .put(LinkingVpnService.EXTRA_API_PORT, apiPort)
                .put(LinkingVpnService.EXTRA_DETAILED_TRAFFIC, detailedTraffic)
                .put(LinkingVpnService.EXTRA_APP_MODE, appMode)
                .put(LinkingVpnService.EXTRA_APPS, apps)
                .put(LinkingVpnService.EXTRA_TITLE, title)
                .put(LinkingVpnService.EXTRA_TEXT, text)
                .put(LinkingVpnService.EXTRA_STOP_LABEL, stopLabel);
            write(new File(context.getFilesDir(), FILE), o.toString());
        } catch (JSONException e) {
            throw new IOException(e);
        }
    }

    static boolean exists(Context context) {
        return new File(context.getFilesDir(), FILE).isFile();
    }

    static void forget(Context context) {
        new File(context.getFilesDir(), FILE).delete();
    }

    /**
     * $target with the extras of the last connection, its Xray config written where the service reads it; null when
     * there is none to make again.
     */
    static Intent intent(Context context, Intent target) throws IOException {
        File f = new File(context.getFilesDir(), FILE);
        if (!f.isFile()) {
            return null;
        }
        try {
            JSONObject o = new JSONObject(new String(java.nio.file.Files.readAllBytes(f.toPath()), StandardCharsets.UTF_8));
            File config = new File(context.getFilesDir(), "xray.json");
            write(config, o.getString("config"));
            return target
                .putExtra(LinkingVpnService.EXTRA_CONFIG, config.getPath())
                .putExtra(LinkingVpnService.EXTRA_SOCKS_PORT, o.getInt(LinkingVpnService.EXTRA_SOCKS_PORT))
                .putExtra(LinkingVpnService.EXTRA_API_PORT, o.getInt(LinkingVpnService.EXTRA_API_PORT))
                .putExtra(LinkingVpnService.EXTRA_DETAILED_TRAFFIC, o.getBoolean(LinkingVpnService.EXTRA_DETAILED_TRAFFIC))
                .putExtra(LinkingVpnService.EXTRA_APP_MODE, o.getString(LinkingVpnService.EXTRA_APP_MODE))
                .putExtra(LinkingVpnService.EXTRA_APPS, o.getString(LinkingVpnService.EXTRA_APPS))
                .putExtra(LinkingVpnService.EXTRA_TITLE, o.getString(LinkingVpnService.EXTRA_TITLE))
                .putExtra(LinkingVpnService.EXTRA_TEXT, o.getString(LinkingVpnService.EXTRA_TEXT))
                .putExtra(LinkingVpnService.EXTRA_STOP_LABEL, o.getString(LinkingVpnService.EXTRA_STOP_LABEL));
        } catch (JSONException e) {
            throw new IOException("the last connection cannot be read", e);
        }
    }

    private static void write(File f, String text) throws IOException {
        try (FileOutputStream out = new FileOutputStream(f)) {
            out.write(text.getBytes(StandardCharsets.UTF_8));
        }
    }
}

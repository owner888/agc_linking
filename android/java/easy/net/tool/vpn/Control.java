package easy.net.tool.vpn;

import android.content.Context;
import android.content.Intent;
import android.net.VpnService;
import android.util.Log;
import java.io.IOException;

/**
 * Connecting and disconnecting from outside the app's screen (the tile, the boot, a broadcast), in the app's main
 * process. A connection is made again from what the last one was made with, and only when the system's VPN permission
 * is already given: no dialog is shown from here.
 */
final class Control {
    private static final String TAG = "LinkingVpn";

    private Control() {}

    /** True when a connection is up or on its way; false when there was nothing to make it from or the permission is not there. */
    static boolean connect(Context context) {
        if (VpnSession.isRunning(context)) {
            return true;
        }
        if (VpnService.prepare(context) != null) {
            Log.w(TAG, "no VPN permission yet: connect once from the app");
            return false;
        }
        try {
            Intent start = LastSession.intent(context, new Intent(context, LinkingVpnService.class).setAction(LinkingVpnService.ACTION_START));
            if (start == null) {
                Log.w(TAG, "nothing to connect with: connect once from the app");
                return false;
            }
            context.startForegroundService(start);
            return true;
        } catch (IOException | RuntimeException e) {
            Log.e(TAG, "connect failed", e);
            return false;
        }
    }

    static void disconnect(Context context) {
        context.startService(new Intent(context, LinkingVpnService.class).setAction(LinkingVpnService.ACTION_STOP));
    }
}

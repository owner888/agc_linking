package easy.net.tool.vpn;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.util.Log;

/**
 * The phone started (when the person turned "connect at boot" on) and the app's broadcasts for other apps to drive the
 * connection (only when the person turned them on): CONNECT, DISCONNECT, TOGGLE.
 */
public final class AutomationReceiver extends BroadcastReceiver {
    public static final String ACTION_CONNECT = "easy.net.tool.action.CONNECT";
    public static final String ACTION_DISCONNECT = "easy.net.tool.action.DISCONNECT";
    public static final String ACTION_TOGGLE = "easy.net.tool.action.TOGGLE";
    private static final String TAG = "LinkingVpn";

    @Override
    public void onReceive(Context context, Intent intent) {
        String action = intent.getAction();
        if (action == null) {
            return;
        }
        if (Intent.ACTION_BOOT_COMPLETED.equals(action)) {
            if (Options.enabled(context, Options.AUTOSTART)) {
                Control.connect(context);
            }
            return;
        }
        if (!Options.enabled(context, Options.BROADCAST)) {
            Log.w(TAG, action + " ignored: broadcast control is off");
            return;
        }
        switch (action) {
            case ACTION_CONNECT:
                Control.connect(context);
                break;
            case ACTION_DISCONNECT:
                Control.disconnect(context);
                break;
            case ACTION_TOGGLE:
                if (VpnSession.isRunning(context)) {
                    Control.disconnect(context);
                } else {
                    Control.connect(context);
                }
                break;
            default:
                break;
        }
    }
}

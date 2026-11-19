package easy.net.tool.vpn;

import android.app.PendingIntent;
import android.content.Intent;
import android.graphics.drawable.Icon;
import android.net.VpnService;
import android.os.Build;
import android.os.Handler;
import android.os.Looper;
import android.service.quicksettings.Tile;
import android.service.quicksettings.TileService;
import java.io.IOException;

/**
 * The quick settings tile: one tap connects (from what the last connection was made with) or disconnects. With nothing
 * to connect from, or the system's permission not given yet, it opens the app, or the permission's dialog.
 */
public final class LinkingTileService extends TileService {
    private static final long REFRESH_MS = 1000;
    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Runnable refresh = new Runnable() {
        @Override
        public void run() {
            update();
            handler.postDelayed(this, REFRESH_MS);
        }
    };

    @Override
    public void onStartListening() {
        handler.post(refresh);
    }

    @Override
    public void onStopListening() {
        handler.removeCallbacks(refresh);
    }

    @Override
    public void onClick() {
        if (VpnSession.isRunning(this)) {
            Control.disconnect(this);
        } else if (!Control.connect(this)) {
            openApp();
        }
        update();
    }

    /** The permission's dialog when there is a connection to make, else the app itself. */
    private void openApp() {
        Intent open;
        try {
            Intent consent = VpnService.prepare(this);
            open = consent != null ? LastSession.intent(this, new Intent(this, VpnLauncherActivity.class)) : null;
        } catch (IOException e) {
            open = null;
        }
        if (open == null) {
            open = getPackageManager().getLaunchIntentForPackage(getPackageName());
        }
        if (open == null) {
            return;
        }
        open.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        if (Build.VERSION.SDK_INT >= 34) {
            startActivityAndCollapse(PendingIntent.getActivity(this, 0, open, PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT));
        } else {
            startActivityAndCollapse(open);
        }
    }

    private void update() {
        Tile tile = getQsTile();
        if (tile == null) {
            return;
        }
        String state = VpnSession.state(this);
        tile.setLabel(getApplicationInfo().loadLabel(getPackageManager()));
        tile.setIcon(Icon.createWithResource(this, android.R.drawable.ic_lock_lock));
        switch (state) {
            case VpnSession.CONNECTED:
                tile.setState(Tile.STATE_ACTIVE);
                tile.setSubtitle("Connected");
                break;
            case VpnSession.CONNECTING:
                tile.setState(Tile.STATE_ACTIVE);
                tile.setSubtitle("Connecting");
                break;
            default:
                tile.setState(Tile.STATE_INACTIVE);
                tile.setSubtitle(VpnSession.ERROR.equals(state) ? "Failed" : "Off");
                break;
        }
        tile.updateTile();
    }
}

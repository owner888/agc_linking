package easy.net.tool.vpn;

import android.app.Activity;
import android.content.Intent;
import android.net.VpnService;
import android.os.Bundle;

/**
 * Asks for the system's VPN permission when there is none yet, then starts LinkingVpnService with the extras it
 * was started with. Transparent, so the app's screen stays as it is; not exported.
 */
public final class VpnLauncherActivity extends Activity {
    private static final int REQUEST_VPN = 1;

    @Override
    protected void onCreate(Bundle state) {
        super.onCreate(state);
        if (state != null) {
            return;
        }
        Intent consent = VpnService.prepare(this);
        if (consent == null) {
            startTunnel();
        } else {
            startActivityForResult(consent, REQUEST_VPN);
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        if (requestCode == REQUEST_VPN && resultCode == RESULT_OK) {
            startTunnel();
        }
        finish();
    }

    private void startTunnel() {
        Intent start = new Intent(this, LinkingVpnService.class)
            .setAction(LinkingVpnService.ACTION_START)
            .putExtras(getIntent());
        startForegroundService(start);
        finish();
    }
}

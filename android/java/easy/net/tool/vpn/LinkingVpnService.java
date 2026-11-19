package easy.net.tool.vpn;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.net.VpnService;
import android.os.Build;
import android.os.ParcelFileDescriptor;
import android.util.Log;
import java.io.BufferedReader;
import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStreamReader;
import java.net.InetSocketAddress;
import java.net.Socket;
import java.nio.charset.StandardCharsets;
import java.util.ArrayDeque;
import java.util.concurrent.TimeUnit;

/**
 * The VPN, in the :service process: Xray as a child process (a SOCKS5 inbound on 127.0.0.1), the TUN from
 * establish(), hev-socks5-tunnel between them. The app's own package is kept out of the tunnel, so Xray's sockets
 * to the node do not loop back into it.
 *
 * START carries the path of the Xray config the UI wrote (EXTRA_CONFIG); STOP ends it.
 */
public final class LinkingVpnService extends VpnService {
    public static final String ACTION_START = "easy.net.tool.vpn.START";
    public static final String ACTION_STOP = "easy.net.tool.vpn.STOP";
    /** Path of the Xray JSON config (required). */
    public static final String EXTRA_CONFIG = "config";
    /** Port of the config's SOCKS5 inbound; 10808 when absent. */
    public static final String EXTRA_SOCKS_PORT = "socksPort";
    /** The notification's title, text and disconnect button, in the app's language; the app name when absent. */
    public static final String EXTRA_TITLE = "title";
    public static final String EXTRA_TEXT = "text";
    public static final String EXTRA_STOP_LABEL = "stopLabel";

    private static final String TAG = "LinkingVpn";
    private static final String CHANNEL = "vpn";
    private static final int NOTIFICATION_ID = 1;
    private static final int DEFAULT_SOCKS_PORT = 10808;
    private static final int MTU = 1500;
    private static final long XRAY_READY_MS = 8000;

    private final VpnSession session = new VpnSession();
    private final Object lock = new Object();
    private Process xray;
    private ParcelFileDescriptor tun;
    private boolean tunnelStarted;
    private boolean stopping;
    private boolean active;
    private final ArrayDeque<String> xrayTail = new ArrayDeque<>();

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        String action = intent == null ? null : intent.getAction();
        if (ACTION_START.equals(action)) {
            startForegroundNow(intent);
            Intent copy = new Intent(intent);
            new Thread(() -> start(copy), "vpn-start").start();
            return START_NOT_STICKY;
        }
        stopTunnel(null);
        return START_NOT_STICKY;
    }

    @Override
    public void onRevoke() {
        stopTunnel(null);
    }

    @Override
    public void onDestroy() {
        stopTunnel(null);
        super.onDestroy();
    }

    private void startForegroundNow(Intent intent) {
        NotificationManager nm = getSystemService(NotificationManager.class);
        String label = getApplicationInfo().loadLabel(getPackageManager()).toString();
        nm.createNotificationChannel(new NotificationChannel(CHANNEL, label, NotificationManager.IMPORTANCE_LOW));
        String title = intent.getStringExtra(EXTRA_TITLE);
        String text = intent.getStringExtra(EXTRA_TEXT);
        String stopLabel = intent.getStringExtra(EXTRA_STOP_LABEL);
        Notification.Builder b = new Notification.Builder(this, CHANNEL)
            .setSmallIcon(android.R.drawable.ic_lock_lock)
            .setContentTitle(title == null || title.isEmpty() ? label : title)
            .setOngoing(true);
        if (text != null && !text.isEmpty()) {
            b.setContentText(text);
        }
        Intent launch = getPackageManager().getLaunchIntentForPackage(getPackageName());
        if (launch != null) {
            b.setContentIntent(PendingIntent.getActivity(this, 0, launch, PendingIntent.FLAG_IMMUTABLE));
        }
        if (stopLabel != null && !stopLabel.isEmpty()) {
            Intent stop = new Intent(this, LinkingVpnService.class).setAction(ACTION_STOP);
            b.addAction(new Notification.Action.Builder(null, stopLabel,
                PendingIntent.getService(this, 1, stop, PendingIntent.FLAG_IMMUTABLE)).build());
        }
        if (Build.VERSION.SDK_INT >= 34) {
            startForeground(NOTIFICATION_ID, b.build(), ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE);
        } else {
            startForeground(NOTIFICATION_ID, b.build());
        }
    }

    private void start(Intent intent) {
        synchronized (lock) {
            if (active || !session.acquire(this)) {
                Log.w(TAG, "a tunnel is already up");
                return;
            }
            active = true;
            stopping = false;
        }
        VpnSession.write(this, VpnSession.CONNECTING, null);
        try {
            String config = intent.getStringExtra(EXTRA_CONFIG);
            if (config == null || !new File(config).isFile()) {
                throw new IOException("no Xray config: " + config);
            }
            int port = intent.getIntExtra(EXTRA_SOCKS_PORT, DEFAULT_SOCKS_PORT);
            File assets = geoAssets();
            startXray(config, assets.getPath(), port);
            establish(port);
            VpnSession.write(this, VpnSession.CONNECTED, null);
            startTrafficReport();
            Log.i(TAG, "connected");
        } catch (Exception e) {
            Log.e(TAG, "start failed", e);
            stopTunnel(e.getMessage() == null ? e.toString() : e.getMessage());
        }
    }

    /**
     * The folder Xray reads geoip.dat and geosite.dat from. The APK's copies (assets/geo/) are put there when the
     * app is new or has none; a file the app updated later is left as it is until the next version of the app.
     */
    private File geoAssets() throws IOException {
        File dir = new File(getFilesDir(), "xray-assets");
        dir.mkdirs();
        File marker = new File(dir, ".version");
        String version;
        try {
            version = String.valueOf(getPackageManager().getPackageInfo(getPackageName(), 0).getLongVersionCode());
        } catch (android.content.pm.PackageManager.NameNotFoundException e) {
            throw new IOException("own package not found", e);
        }
        String have = marker.isFile() ? new String(java.nio.file.Files.readAllBytes(marker.toPath()), StandardCharsets.UTF_8) : "";
        for (String name : new String[] {"geoip.dat", "geosite.dat"}) {
            File target = new File(dir, name);
            if (target.isFile() && have.equals(version)) {
                continue;
            }
            try (java.io.InputStream in = getAssets().open("geo/" + name);
                 FileOutputStream out = new FileOutputStream(target)) {
                in.transferTo(out);
            } catch (IOException e) {
                if (!target.isFile()) {
                    throw new IOException(name + " is not in this build", e);
                }
            }
        }
        java.nio.file.Files.write(marker.toPath(), version.getBytes(StandardCharsets.UTF_8));
        return dir;
    }

    /** Once a second, the bytes the tunnel has carried, to the file the UI reads. */
    private void startTrafficReport() {
        Thread t = new Thread(() -> {
            while (true) {
                synchronized (lock) {
                    if (!active || !tunnelStarted) {
                        return;
                    }
                }
                long[] s = Tun2Socks.TProxyGetStats();
                if (s != null && s.length >= 4) {
                    VpnSession.writeTraffic(this, s[1], s[3]);
                }
                try {
                    Thread.sleep(1000);
                } catch (InterruptedException e) {
                    return;
                }
            }
        }, "vpn-traffic");
        t.setDaemon(true);
        t.start();
    }

    private void startXray(String config, String assetDir, int port) throws IOException, InterruptedException {
        File exe = new File(getApplicationInfo().nativeLibraryDir, "libxray.so");
        if (!exe.canExecute()) {
            throw new IOException("libxray.so is not in this build");
        }
        File pidFile = new File(getFilesDir(), "xray.pid");
        killStale(pidFile);
        // exec keeps the shell's pid for Xray, which the pid file keeps so a service that was killed does not leave it running
        ProcessBuilder pb = new ProcessBuilder("/system/bin/sh", "-c",
            "echo $$ > \"$1\"; exec \"$2\" run -c \"$3\"", "sh", pidFile.getPath(), exe.getPath(), config);
        pb.directory(getFilesDir());
        pb.redirectErrorStream(true);
        if (assetDir != null && !assetDir.isEmpty()) {
            pb.environment().put("XRAY_LOCATION_ASSET", assetDir);
        }
        Process p = pb.start();
        synchronized (lock) {
            xray = p;
        }
        Thread pump = new Thread(() -> pumpLog(p), "xray-log");
        pump.setDaemon(true);
        pump.start();
        Thread watch = new Thread(() -> {
            try {
                p.waitFor();
            } catch (InterruptedException e) {
                return;
            }
            boolean expected;
            synchronized (lock) {
                expected = stopping || xray != p;
            }
            if (!expected) {
                stopTunnel("Xray exited: " + tail());
            }
        }, "xray-watch");
        watch.setDaemon(true);
        watch.start();

        long deadline = System.currentTimeMillis() + XRAY_READY_MS;
        while (!socksReady(port)) {
            if (!p.isAlive()) {
                throw new IOException("Xray exited: " + tail());
            }
            if (System.currentTimeMillis() > deadline) {
                throw new IOException("Xray did not open port " + port + ": " + tail());
            }
            Thread.sleep(100);
        }
    }

    private static boolean socksReady(int port) {
        try (Socket s = new Socket()) {
            s.connect(new InetSocketAddress("127.0.0.1", port), 200);
            return true;
        } catch (IOException e) {
            return false;
        }
    }

    private void pumpLog(Process p) {
        try (BufferedReader r = new BufferedReader(new InputStreamReader(p.getInputStream(), StandardCharsets.UTF_8))) {
            String line;
            while ((line = r.readLine()) != null) {
                Log.i("xray", line);
                synchronized (xrayTail) {
                    if (xrayTail.size() >= 10) {
                        xrayTail.removeFirst();
                    }
                    xrayTail.addLast(line);
                }
            }
        } catch (IOException ignored) {
            // the process is gone
        }
    }

    private String tail() {
        synchronized (xrayTail) {
            return String.join("\n", xrayTail);
        }
    }

    private void killStale(File pidFile) {
        try {
            if (!pidFile.isFile()) {
                return;
            }
            String pid = new String(java.nio.file.Files.readAllBytes(pidFile.toPath()), StandardCharsets.UTF_8).trim();
            File cmdline = new File("/proc/" + pid + "/cmdline");
            if (cmdline.isFile() && new String(java.nio.file.Files.readAllBytes(cmdline.toPath()), StandardCharsets.UTF_8).contains("libxray")) {
                android.os.Process.killProcess(Integer.parseInt(pid));
                Thread.sleep(300);
            }
        } catch (IOException | NumberFormatException | InterruptedException e) {
            Log.w(TAG, "stale Xray check failed", e);
        } finally {
            pidFile.delete();
        }
    }

    private void establish(int socksPort) throws IOException {
        Builder b = new Builder()
            .setSession(getApplicationInfo().loadLabel(getPackageManager()).toString())
            .setMtu(MTU)
            .setMetered(false)
            .addAddress("198.18.0.1", 32)
            .addAddress("fc00::1", 128)
            .addRoute("0.0.0.0", 0)
            .addRoute("::", 0)
            .addDnsServer("1.1.1.1")
            .addDnsServer("8.8.8.8");
        try {
            b.addDisallowedApplication(getPackageName());
        } catch (android.content.pm.PackageManager.NameNotFoundException e) {
            throw new IOException("own package not found", e);
        }
        ParcelFileDescriptor fd = b.establish();
        if (fd == null) {
            throw new IOException("the VPN permission was revoked");
        }
        File yaml = new File(getFilesDir(), "hev.yml");
        String conf = "tunnel:\n  mtu: " + MTU + "\n  ipv4: 198.18.0.1\n  ipv6: 'fc00::1'\n"
            + "socks5:\n  port: " + socksPort + "\n  address: 127.0.0.1\n  udp: 'udp'\n";
        try (FileOutputStream out = new FileOutputStream(yaml)) {
            out.write(conf.getBytes(StandardCharsets.UTF_8));
        }
        synchronized (lock) {
            tun = fd;
        }
        if (!Tun2Socks.TProxyStartService(yaml.getPath(), fd.getFd())) {
            throw new IOException("hev-socks5-tunnel did not start");
        }
        synchronized (lock) {
            tunnelStarted = true;
        }
    }

    /** Takes it all down, once; error is the message the UI reads, null for a stop that was asked for. */
    private void stopTunnel(String error) {
        Process p;
        ParcelFileDescriptor fd;
        boolean hev;
        synchronized (lock) {
            if (!active) {
                stopSelf();
                return;
            }
            active = false;
            stopping = true;
            p = xray;
            xray = null;
            fd = tun;
            tun = null;
            hev = tunnelStarted;
            tunnelStarted = false;
        }
        if (hev) {
            Tun2Socks.TProxyStopService();
        }
        if (fd != null) {
            try {
                fd.close();
            } catch (IOException ignored) {
                // already closed
            }
        }
        if (p != null) {
            p.destroy();
            try {
                if (!p.waitFor(2, TimeUnit.SECONDS)) {
                    p.destroyForcibly();
                }
            } catch (InterruptedException e) {
                p.destroyForcibly();
            }
        }
        new File(getFilesDir(), "xray.pid").delete();
        new File(getFilesDir(), "xray.json").delete(); // it holds the node's secrets
        VpnSession.clearTraffic(this);
        VpnSession.write(this, error == null ? null : VpnSession.ERROR, error);
        session.release();
        stopForeground(STOP_FOREGROUND_REMOVE);
        stopSelf();
    }
}

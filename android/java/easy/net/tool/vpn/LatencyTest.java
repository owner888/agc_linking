package easy.net.tool.vpn;

import android.content.Context;
import android.util.Log;
import java.io.BufferedReader;
import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStreamReader;
import java.net.InetSocketAddress;
import java.net.Proxy;
import java.net.Socket;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * Latency of proxies, in the UI's process, off its thread: start one of the tests, then ask poll() until nothing is
 * pending. One test runs at a time; a new start ends the one before.
 *
 * URL (Shadowrocket's CONNECT): one Xray holds every proxy as an outbound behind a SOCKS inbound of its own, and a
 * request for the URL goes through each; the time it takes is the latency. ICMP: the system's ping.
 */
public final class LatencyTest {
    /** Not yet measured, in poll(). */
    public static final long PENDING = -2;
    /** Failed or timed out, in poll(). */
    public static final long FAILED = -1;

    private static final String TAG = "LatencyTest";
    private static final int PARALLEL = 8;
    private static final Pattern PING_TIME = Pattern.compile("time[=<]([0-9.]+)\\s*ms");

    private static final Object LOCK = new Object();
    private static long[] results = new long[0];
    private static int generation;
    private static Process xray;
    private static ExecutorService pool;

    private LatencyTest() {}

    /**
     * @param configJson Xray config whose inbounds are the SOCKS ports below
     * @param ports      comma-separated, one per proxy in the order poll() answers; 0 for one that has no inbound (failed at once)
     */
    public static void startUrl(Context context, String configJson, String ports, String url, int timeoutMs) {
        String[] p = ports.split(",");
        int gen = begin(p.length);
        Thread t = new Thread(() -> runUrl(context, gen, configJson, p, url, timeoutMs), "latency-url");
        t.setDaemon(true);
        t.start();
    }

    /** @param hosts comma-separated, one per proxy */
    public static void startPing(String hosts, int timeoutMs) {
        String[] h = hosts.split(",");
        int gen = begin(h.length);
        ExecutorService ex = pool(gen);
        for (int i = 0; i < h.length; i++) {
            int index = i;
            ex.execute(() -> finish(gen, index, ping(h[index], timeoutMs)));
        }
        ex.shutdown();
    }

    /** One number per proxy: milliseconds, FAILED (-1) or PENDING (-2); comma-separated. */
    public static String poll() {
        StringBuilder s = new StringBuilder();
        synchronized (LOCK) {
            for (int i = 0; i < results.length; i++) {
                s.append(i == 0 ? "" : ",").append(results[i]);
            }
        }
        return s.toString();
    }

    /** Ends the test: what was not measured stays PENDING for nobody to read. */
    public static void cancel() {
        begin(0);
    }

    private static int begin(int count) {
        Process old;
        ExecutorService oldPool;
        int gen;
        synchronized (LOCK) {
            gen = ++generation;
            results = new long[count];
            java.util.Arrays.fill(results, PENDING);
            old = xray;
            xray = null;
            oldPool = pool;
            pool = null;
        }
        if (old != null) {
            old.destroy();
        }
        if (oldPool != null) {
            oldPool.shutdownNow();
        }
        return gen;
    }

    private static ExecutorService pool(int gen) {
        ExecutorService ex = Executors.newFixedThreadPool(PARALLEL);
        synchronized (LOCK) {
            if (gen == generation) {
                pool = ex;
            }
        }
        return ex;
    }

    private static void finish(int gen, int index, long ms) {
        synchronized (LOCK) {
            if (gen == generation && index < results.length) {
                results[index] = ms;
            }
        }
    }

    private static void runUrl(Context context, int gen, String configJson, String[] ports, String url, int timeoutMs) {
        File config = new File(context.getFilesDir(), "probe.json");
        Process p = null;
        try {
            try (FileOutputStream out = new FileOutputStream(config)) {
                out.write(configJson.getBytes(StandardCharsets.UTF_8));
            }
            File exe = new File(context.getApplicationInfo().nativeLibraryDir, "libxray.so");
            p = new ProcessBuilder(exe.getPath(), "run", "-c", config.getPath()).redirectErrorStream(true).start();
            Process started = p;
            Thread drain = new Thread(() -> drain(started), "latency-xray-log");
            drain.setDaemon(true);
            drain.start();
            synchronized (LOCK) {
                if (gen != generation) {
                    p.destroy();
                    return;
                }
                xray = p;
            }
            int first = -1;
            for (int i = 0; i < ports.length; i++) {
                if (Integer.parseInt(ports[i].trim()) > 0) {
                    first = i;
                    break;
                }
            }
            if (first < 0 || !waitForPort(Integer.parseInt(ports[first].trim()), p, 8000)) {
                Log.w(TAG, "Xray did not open its test port");
                for (int i = 0; i < ports.length; i++) {
                    finish(gen, i, FAILED);
                }
                return;
            }
            ExecutorService ex = pool(gen);
            for (int i = 0; i < ports.length; i++) {
                int index = i;
                int port = Integer.parseInt(ports[i].trim());
                ex.execute(() -> finish(gen, index, port > 0 ? request(port, url, timeoutMs) : FAILED));
            }
            ex.shutdown();
            ex.awaitTermination(timeoutMs * 3L + 10000L, TimeUnit.MILLISECONDS);
        } catch (IOException | InterruptedException | NumberFormatException e) {
            Log.w(TAG, "URL test failed", e);
            for (int i = 0; i < ports.length; i++) {
                finish(gen, i, FAILED);
            }
        } finally {
            if (p != null) {
                p.destroy();
            }
            synchronized (LOCK) {
                if (xray == p) {
                    xray = null;
                }
            }
            config.delete(); // it holds the proxies' secrets
        }
    }

    private static void drain(Process p) {
        try (BufferedReader r = new BufferedReader(new InputStreamReader(p.getInputStream(), StandardCharsets.UTF_8))) {
            while (r.readLine() != null) {
                // Xray's log is of no use here; reading it keeps the process from blocking on a full pipe
            }
        } catch (IOException ignored) {
            // the process is gone
        }
    }

    private static boolean waitForPort(int port, Process p, long maxMs) throws InterruptedException {
        long deadline = System.currentTimeMillis() + maxMs;
        while (p.isAlive() && System.currentTimeMillis() < deadline) {
            try (Socket s = new Socket()) {
                s.connect(new InetSocketAddress("127.0.0.1", port), 200);
                return true;
            } catch (IOException e) {
                Thread.sleep(100);
            }
        }
        return false;
    }

    /**
     * Milliseconds a request for the URL takes through the SOCKS port, until its status line is read; FAILED when it is
     * not answered or the answer is not a success or a redirect. The request is written on the socket itself: the
     * system's HTTP client refuses a plain http URL, which is the usual one for a test (generate_204).
     */
    private static long request(int port, String url, int timeoutMs) {
        long start = System.nanoTime();
        try {
            URL u = new URL(url);
            boolean tls = u.getProtocol().equals("https");
            int targetPort = u.getPort() > 0 ? u.getPort() : (tls ? 443 : 80);
            String path = u.getFile().isEmpty() ? "/" : u.getFile();
            try (Socket plain = new Socket(new Proxy(Proxy.Type.SOCKS, new InetSocketAddress("127.0.0.1", port)))) {
                plain.setSoTimeout(timeoutMs);
                // unresolved: the name goes to the proxy, which resolves it on its side
                plain.connect(InetSocketAddress.createUnresolved(u.getHost(), targetPort), timeoutMs);
                Socket socket = plain;
                if (tls) {
                    javax.net.ssl.SSLSocket ssl = (javax.net.ssl.SSLSocket) ((javax.net.ssl.SSLSocketFactory) javax.net.ssl.SSLSocketFactory.getDefault())
                        .createSocket(plain, u.getHost(), targetPort, true);
                    ssl.startHandshake();
                    socket = ssl;
                }
                String request = "GET " + path + " HTTP/1.1\r\nHost: " + u.getHost() + "\r\nUser-Agent: Linking\r\nConnection: close\r\n\r\n";
                socket.getOutputStream().write(request.getBytes(StandardCharsets.US_ASCII));
                socket.getOutputStream().flush();
                BufferedReader r = new BufferedReader(new InputStreamReader(socket.getInputStream(), StandardCharsets.ISO_8859_1));
                String status = r.readLine(); // HTTP/1.1 204 No Content
                long ms = (System.nanoTime() - start) / 1_000_000L;
                String[] parts = status == null ? new String[0] : status.split(" ");
                int code = parts.length > 1 ? Integer.parseInt(parts[1]) : 0;
                return code >= 200 && code < 400 ? Math.max(1, ms) : FAILED;
            }
        } catch (IOException | NumberFormatException e) {
            Log.w(TAG, "request through port " + port + " failed: " + e);
            return FAILED;
        }
    }

    private static long ping(String host, int timeoutMs) {
        Process p = null;
        try {
            int seconds = Math.max(1, (timeoutMs + 999) / 1000);
            p = new ProcessBuilder("ping", "-c", "1", "-W", String.valueOf(seconds), host).redirectErrorStream(true).start();
            String out = new String(p.getInputStream().readAllBytes(), StandardCharsets.UTF_8);
            p.waitFor();
            Matcher m = PING_TIME.matcher(out);
            return m.find() ? Math.max(1, Math.round(Double.parseDouble(m.group(1)))) : FAILED;
        } catch (IOException | InterruptedException | NumberFormatException e) {
            return FAILED;
        } finally {
            if (p != null) {
                p.destroy();
            }
        }
    }
}

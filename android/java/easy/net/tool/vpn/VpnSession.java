package easy.net.tool.vpn;

import android.content.Context;
import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.RandomAccessFile;
import java.nio.channels.FileChannel;
import java.nio.channels.FileLock;
import java.nio.channels.OverlappingFileLockException;
import java.nio.charset.StandardCharsets;

/**
 * Whether the tunnel is up, shared between the app's two processes without a static variable: the :service process
 * holds a file lock while a tunnel is being made or is up, the process of the UI asks the lock. The kernel drops
 * the lock when the process dies, so a killed service never reads as running. The state file says which stage.
 * Closing any file descriptor of the lock file drops a POSIX lock of the process, so isRunning() and state() are
 * for the UI's process; the service asks nothing of them.
 */
public final class VpnSession {
    public static final String STOPPED = "stopped";
    public static final String CONNECTING = "connecting";
    public static final String CONNECTED = "connected";
    public static final String ERROR = "error";

    private static final String LOCK_FILE = "vpn.lock";
    private static final String STATE_FILE = "vpn.state";
    private static final String TRAFFIC_FILE = "vpn.traffic";

    private RandomAccessFile file;
    private FileLock lock;

    /** Takes the lock; false when a tunnel already holds it. */
    synchronized boolean acquire(Context context) {
        if (lock != null) {
            return false;
        }
        try {
            file = new RandomAccessFile(new File(context.getFilesDir(), LOCK_FILE), "rw");
            lock = file.getChannel().tryLock();
        } catch (IOException | OverlappingFileLockException e) {
            lock = null;
        }
        if (lock == null) {
            closeFile();
            return false;
        }
        return true;
    }

    synchronized void release() {
        try {
            if (lock != null) {
                lock.release();
            }
        } catch (IOException ignored) {
            // closing the file drops the lock all the same
        }
        lock = null;
        closeFile();
    }

    private void closeFile() {
        try {
            if (file != null) {
                file.close();
            }
        } catch (IOException ignored) {
            // nothing left to release
        }
        file = null;
    }

    /** A stage (connecting, connected), the error with its message, or none (a clean stop). */
    static void write(Context context, String state, String message) {
        File f = new File(context.getFilesDir(), STATE_FILE);
        if (state == null) {
            f.delete();
            return;
        }
        try (FileOutputStream out = new FileOutputStream(f)) {
            out.write((message == null ? state : state + "\n" + message).getBytes(StandardCharsets.UTF_8));
        } catch (IOException ignored) {
            // the state is advice for the UI; the lock is what counts
        }
    }

    private static String[] read(Context context) {
        File f = new File(context.getFilesDir(), STATE_FILE);
        if (!f.isFile()) {
            return new String[] {STOPPED, ""};
        }
        try (java.io.FileInputStream in = new java.io.FileInputStream(f)) {
            String text = new String(in.readAllBytes(), StandardCharsets.UTF_8);
            int nl = text.indexOf('\n');
            return nl < 0 ? new String[] {text, ""} : new String[] {text.substring(0, nl), text.substring(nl + 1)};
        } catch (IOException e) {
            return new String[] {STOPPED, ""};
        }
    }

    /** The bytes the tunnel has carried since it came up, for the UI to read: "uploaded downloaded", then, when asked for, proxy up and down, direct up and down. */
    static void writeTraffic(Context context, long tx, long rx, long[] byOutbound) {
        StringBuilder text = new StringBuilder().append(tx).append(' ').append(rx);
        for (int i = 0; byOutbound != null && i < byOutbound.length; i++) {
            text.append(' ').append(byOutbound[i]);
        }
        try (FileOutputStream out = new FileOutputStream(new File(context.getFilesDir(), TRAFFIC_FILE))) {
            out.write(text.toString().getBytes(StandardCharsets.UTF_8));
        } catch (IOException ignored) {
            // a count missed is made up by the next
        }
    }

    static void clearTraffic(Context context) {
        new File(context.getFilesDir(), TRAFFIC_FILE).delete();
    }

    /** The same text, empty when no tunnel is up. */
    public static String traffic(Context context) {
        if (!isRunning(context)) {
            return "";
        }
        try (java.io.FileInputStream in = new java.io.FileInputStream(new File(context.getFilesDir(), TRAFFIC_FILE))) {
            return new String(in.readAllBytes(), StandardCharsets.UTF_8);
        } catch (IOException e) {
            return "";
        }
    }

    /** True while a tunnel is being made or is up (whichever process asks). */
    public static boolean isRunning(Context context) {
        try (RandomAccessFile f = new RandomAccessFile(new File(context.getFilesDir(), LOCK_FILE), "rw");
             FileChannel ch = f.getChannel()) {
            FileLock l = ch.tryLock();
            if (l == null) {
                return true;
            }
            l.release();
            return false;
        } catch (OverlappingFileLockException e) {
            return true;
        } catch (IOException e) {
            return false;
        }
    }

    /** One of the constants: with the lock free, a stale connecting or connected reads as stopped. */
    public static String state(Context context) {
        String s = read(context)[0];
        if (isRunning(context)) {
            return s.equals(CONNECTING) || s.equals(CONNECTED) ? s : CONNECTING;
        }
        return s.equals(ERROR) ? ERROR : STOPPED;
    }

    /** The message of the last error, empty when there is none. */
    public static String error(Context context) {
        String[] s = read(context);
        return s[0].equals(ERROR) ? s[1] : "";
    }
}

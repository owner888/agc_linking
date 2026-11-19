package easy.net.tool.vpn;

/**
 * hev-socks5-tunnel: the TUN's packets to the local SOCKS5 port. The natives are registered by the library's own
 * JNI_OnLoad under this class's name (scripts/build-native.sh sets it), so the name and the signatures stay as they are.
 */
final class Tun2Socks {
    static {
        System.loadLibrary("hev-socks5-tunnel");
    }

    private Tun2Socks() {}

    /** Runs the tunnel on a thread of its own over the TUN's fd, the settings in the YAML file at configPath. */
    static native boolean TProxyStartService(String configPath, int fd);

    /** Stops it and waits for the thread. */
    static native boolean TProxyStopService();

    static native boolean TProxyIsRunning();

    /** tx packets, tx bytes, rx packets, rx bytes. */
    static native long[] TProxyGetStats();
}

package easy.net.tool.vpn;

import android.content.Context;
import java.io.File;
import java.io.IOException;

/**
 * The automations the person turned on, as a file each in the app's folder: the receivers and the tile read them with
 * no PHP and no UI process, and a file is shared by whichever process asks.
 */
final class Options {
    static final String AUTOSTART = "autostart";
    static final String BROADCAST = "broadcast";

    private Options() {}

    private static File file(Context context, String name) {
        return new File(context.getFilesDir(), "option." + name);
    }

    static boolean enabled(Context context, String name) {
        return file(context, name).exists();
    }

    static void set(Context context, String name, boolean on) {
        File f = file(context, name);
        if (on) {
            try {
                f.createNewFile();
            } catch (IOException e) {
                throw new IllegalStateException("cannot keep the option " + name, e);
            }
        } else {
            f.delete();
        }
    }
}

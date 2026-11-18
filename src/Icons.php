<?php
declare(strict_types=1);

use MyPHP\UI\SVG;

/**
 * The app's own pictures as SVG: the tab and toolbar icons (24 points, drawn in the color they are shown in, as
 * currentColor), and the bird of the connection button. Android has only some of the system symbols, so these are the
 * same on every platform.
 */
final class Icons
{
    /** @var array<string, SVG> */
    private static array $cache = [];

    private const STROKE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">%s</svg>';

    private const PATHS = [
        'power' => '<path d="M12 3v9"/><path d="M7.2 6.4a8 8 0 1 0 9.6 0"/>',
        'filter' => '<path d="M4 7h16"/><path d="M7 12h10"/><path d="M10 17h4"/>',
        'menu' => '<path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3c2.6 2.6 4 5.7 4 9s-1.4 6.4-4 9c-2.6-2.6-4-5.7-4-9s1.4-6.4 4-9z"/>',
        'sliders' => '<path d="M4 8h9"/><path d="M17 8h3"/><circle cx="15" cy="8" r="2"/><path d="M4 16h3"/><path d="M11 16h9"/><circle cx="9" cy="16" r="2"/>',
        'plus' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8"/><path d="M8 12h8"/>',
        'chevron.down' => '<path d="m6 9 6 6 6-6"/>',
        'bolt' => '<path d="M13 3 5 13h6l-1 8 8-10h-6l1-8z"/>',
        'sort' => '<path d="M7 4v16"/><path d="m3 16 4 4 4-4"/><path d="M17 20V4"/><path d="m13 8 4-4 4 4"/>',
        'network' => '<circle cx="12" cy="5" r="2.5"/><circle cx="5" cy="19" r="2.5"/><circle cx="19" cy="19" r="2.5"/><path d="M12 7.5v4"/><path d="M12 11.5 5 16.5"/><path d="m12 11.5 7 5"/>',
        'doc' => '<path d="M7 3h7l4 4v14H7z"/><path d="M14 3v4h4"/><path d="M10 12h5"/><path d="M10 16h5"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3"/><path d="M12 19v3"/><path d="M2 12h3"/><path d="M19 12h3"/><path d="m4.9 4.9 2.1 2.1"/><path d="m17 17 2.1 2.1"/><path d="M4.9 19.1 7 17"/><path d="m17 7 2.1-2.1"/>',
        'pulse' => '<path d="M3 12h4l2-6 4 12 2-6h6"/>',
        'plug' => '<path d="M9 3v5"/><path d="M15 3v5"/><path d="M7 8h10v4a5 5 0 0 1-10 0z"/><path d="M12 17v4"/>',
        'send' => '<path d="m21 3-9.5 18-2-8-8-2z"/><path d="m21 3-11.5 10"/>',
        'star' => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
        'chevron.right' => '<path d="m9 6 6 6-6 6"/>',
        'xmark' => '<path d="M6 6l12 12"/><path d="M18 6 6 18"/>',
        'trash' => '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13h10l1-13"/>',
        'wifi.off' => '<path d="M2 8.8a15 15 0 0 1 4-2.4"/><path d="M9 4.3A15 15 0 0 1 22 8.8"/><path d="M5 12.9a10 10 0 0 1 3.2-2"/><path d="M12 9.6a10 10 0 0 1 7 3.3"/><path d="M8.5 16.4a5 5 0 0 1 7 0"/><path d="M12 20h.01"/><path d="m2 2 20 20"/>',
    ];

    /** One of the line icons by name, in the color it is shown in. */
    public static function get(string $name): SVG
    {
        return self::$cache[$name] ??= SVG::parse(sprintf(self::STROKE, self::PATHS[$name]));
    }

    /** The bird of the connection button: the one that goes with a connection, and the one without. */
    public static function bird(bool $connected): SVG
    {
        return self::$cache[$connected ? 'bird.on' : 'bird.off'] ??= SVG::parse($connected ? self::CONNECTED : self::DISCONNECTED);
    }

    private const CONNECTED = <<<'SVG'
<svg width="641" height="531" viewBox="0 0 641 531" fill="none" xmlns="http://www.w3.org/2000/svg">
<ellipse cx="261.812" cy="253.052" rx="130.62" ry="228.333" transform="rotate(47.8897 261.812 253.052)" fill="#3FA2F0"/>
<path d="M641 530.992L385.857 433.671L396.579 470.508L439.099 502.562L451.63 499.161L459.724 505.034L437.392 511.426L420.928 516.43L412.955 510.539L428.082 506.748C415.899 496.426 389.656 477.408 385.745 473.661C380.855 468.978 353.572 455.995 324.966 438.467C316.17 433.077 297.159 419.771 274.235 403.037C299.612 389.48 325.066 371.678 349.1 349.954C372.458 328.841 392.265 306.021 408.088 282.83C490.594 370.742 578.743 464.663 641 530.992ZM113.512 22.8742C132.068 13.9479 146.961 16.6643 156.437 20.9574C165.914 25.2506 174.169 33.4252 181.025 40.8313C183.564 43.574 249.936 114.31 335.482 205.467C322.067 230.234 302.483 255.234 277.629 277.699C247.516 304.918 214.482 324.068 183.582 334.048C167.891 321.515 153.987 309.955 143.869 300.792C85.2679 247.723 115.323 148.879 117.249 120.505L0 3.81693L94.9783 57.3586L96.946 53.8693L1.38575 0L113.512 22.8742ZM139.65 33.6884C131.907 33.6884 125.615 39.9653 125.596 47.7082C125.577 55.4511 131.839 61.7281 139.582 61.7281C147.324 61.7281 153.617 55.4511 153.636 47.7082C153.654 39.9655 147.393 33.6886 139.65 33.6884ZM139.635 39.6969C144.06 39.6971 147.638 43.2838 147.627 47.7082C147.616 52.1327 144.021 55.7195 139.596 55.7196C135.172 55.7196 131.594 52.1327 131.604 47.7082C131.615 43.2837 135.211 39.6969 139.635 39.6969Z" fill="#FFFFFF"/>
</svg>
SVG;

    private const DISCONNECTED = <<<'SVG'
<svg width="328" height="272" viewBox="0 0 328 272" fill="none" xmlns="http://www.w3.org/2000/svg">
<ellipse cx="143.476" cy="139.963" rx="66.8108" ry="132.36" transform="rotate(47.8897 143.476 139.963)" fill="#3FA2F0"/>
<path d="M219.858 155.068C258.462 196.923 298.602 240.272 328 271.593L197.497 221.814C197.528 221.854 213.526 242.339 224.73 257.052C224.73 257.052 185.296 235.875 166.352 224.267C163.718 222.653 159.293 219.649 153.723 215.719C165.321 208.41 176.936 199.639 188.129 189.521C200.309 178.512 210.954 166.846 219.858 155.068ZM58.1956 11.697C67.6866 7.13141 79.0813 8.52088 83.9282 10.7168C88.7749 12.9127 92.9978 17.0934 96.5047 20.8814C97.8879 22.3755 135.336 63.2611 182.216 114.206C175.345 127.165 165.158 140.284 152.147 152.045C137.363 165.408 121.202 174.968 105.952 180.229C93.1845 170.326 81.35 160.757 73.7225 153.849C43.7488 126.705 59.1217 76.1468 60.1071 61.6336L0 1.81382L49.6855 29.8185C50.1669 30.0898 50.7773 29.9196 51.0488 29.4384C51.3032 28.9869 51.1692 28.4222 50.7546 28.1298L50.6686 28.0751L0.856091 0L58.1956 11.697ZM75.5197 17.0202C70.3053 17.0202 66.0675 21.2472 66.0548 26.4616C66.0424 31.6758 70.2595 35.903 75.4738 35.903C80.688 35.903 84.9248 31.6757 84.9377 26.4616C84.9503 21.2473 80.734 17.0203 75.5197 17.0202ZM75.5099 21.0661C78.4894 21.0663 80.899 23.4821 80.8918 26.4616C80.8843 29.441 78.463 31.8561 75.4835 31.8562C72.504 31.8562 70.0947 29.4411 70.1017 26.4616C70.1089 23.482 72.5303 21.0661 75.5099 21.0661Z" fill="#FFFFFF"/>
</svg>
SVG;
}

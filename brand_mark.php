<?php
/**
 * LUEPiN logotype — Playfair Display (ใกล้เคียงฟอนต์ IG Story "Literature")
 * ตัว i เล็ก = หมุดบนแผนที่ (เน้นสี #E2E800)
 */

function brand_mark_font_link() {
    return 'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&display=swap';
}

function brand_mark_css() {
    return <<<'CSS'
.luepin-mark {
  font-family: 'Playfair Display', Georgia, 'Times New Roman', serif;
  font-weight: 700;
  letter-spacing: 0.14em;
  line-height: 1;
  user-select: none;
  display: inline-block;
}
.luepin-mark--sm  { font-size: 1.125rem; }
.luepin-mark--md  { font-size: 1.75rem; }
.luepin-mark--lg  { font-size: 2.25rem; }
.luepin-mark--xl  { font-size: 2.75rem; letter-spacing: 0.16em; }

.luepin-mark--dark  { color: #F0F0F0; }
.luepin-mark--light { color: #141414; }

.luepin-mark .luepin-pin-i {
  display: inline-block;
  font-size: 0.9em;
  font-weight: 600;
  letter-spacing: 0;
  color: #E2E800;
}
CSS;
}

/**
 * @param string $size  sm | md | lg | xl
 * @param string $theme dark | light
 */
function render_luepin_mark($size = 'md', $theme = 'dark') {
    $size  = in_array($size, ['sm', 'md', 'lg', 'xl'], true) ? $size : 'md';
    $theme = $theme === 'light' ? 'light' : 'dark';
    $class = "luepin-mark luepin-mark--{$size} luepin-mark--{$theme}";
    echo '<span class="' . $class . '" aria-label="LUEPiN">';
    echo 'LUEP<span class="luepin-pin-i">i</span>N';
    echo '</span>';
}

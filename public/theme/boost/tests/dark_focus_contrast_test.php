<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace theme_boost;

use core\output\theme_config;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Unit tests for the contrast of the focus indicators in the dark colour mode.
 *
 * These tests assert the content of the theme's compiled CSS rather than the behaviour of any
 * one class, so there is no meaningful coverage target.
 *
 * @package   theme_boost
 * @copyright 2026 Moodle Pty Ltd
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class dark_focus_contrast_test extends \advanced_testcase {
    /** @var string[]|null The custom properties of the dark palette, compiled once for the whole class. */
    private static ?array $darkpalette = null;

    /** @var string[] The dark surfaces a focus indicator is drawn on, and the property each is read from. */
    private const SURFACES = [
        'page' => '--bs-gray-100',
        'drawer' => '--bs-gray-200',
        'card' => '--bs-white',
    ];

    /**
     * The design system focus colour reaches 3:1 on every dark surface.
     *
     * The token is an alias of the primary interactive border in the design system, but the SCSS token build resolves
     * it to a fixed colour, so re-pointing the border for dark mode left every focus outline drawn from this token in
     * its light mode colour.
     */
    public function test_design_system_focus_colour(): void {
        $palette = $this->get_dark_palette();

        $this->assertArrayHasKey('--mds-focus-default', $palette, 'The dark palette does not re-point the focus colour.');
        $this->assertSame(
            $palette['--mds-border-interactive-primary-default'],
            $palette['--mds-focus-default'],
            'The focus colour no longer matches the primary interactive border it is an alias of.'
        );

        $focus = $this->parse_colour($palette['--mds-focus-default']);
        foreach (self::SURFACES as $surface => $property) {
            $background = $this->parse_colour($palette[$property]);
            $this->assertGreaterThanOrEqual(3, $this->contrast($focus, $background), "Focus colour on the {$surface}.");
        }
    }

    /**
     * The Bootstrap focus ring reaches 3:1 on every dark surface once its opacity is applied.
     */
    public function test_bootstrap_focus_ring(): void {
        $palette = $this->get_dark_palette();

        $ring = $this->parse_colour($palette['--bs-focus-ring-color']);
        $this->assertLessThan(1, $ring[3], 'The focus ring is expected to be translucent.');
        foreach (self::SURFACES as $surface => $property) {
            $background = $this->parse_colour($palette[$property]);
            $this->assertGreaterThanOrEqual(
                3,
                $this->contrast($this->blend($ring, $background), $background),
                "Focus ring on the {$surface}."
            );
        }
    }

    /**
     * Compile Boost and return the custom properties of the root dark palette.
     *
     * @return string[] The values, keyed by property name.
     */
    private function get_dark_palette(): array {
        if (self::$darkpalette === null) {
            $this->resetAfterTest();
            $css = theme_config::load('boost')->get_css_content_debug('scss', null, null);

            // The root palette is the dark block which re-points the primary interactive border.
            $matched = preg_match(
                '/\[data-bs-theme=["\']?dark["\']?\]\s*\{([^{}]*--mds-border-interactive-primary-default:[^{}]*)\}/',
                $css,
                $matches
            );
            $this->assertSame(1, $matched, 'The compiled CSS has no dark palette.');

            preg_match_all('/(--[\w-]+)\s*:\s*([^;]+)/', $matches[1], $declarations, PREG_SET_ORDER);
            self::$darkpalette = [];
            foreach ($declarations as [, $name, $value]) {
                self::$darkpalette[$name] = strtolower(trim($value));
            }
        }
        return self::$darkpalette;
    }

    /**
     * Parse a hex, rgb() or rgba() colour.
     *
     * @param string $colour The CSS colour.
     * @return float[] Red, green and blue from 0 to 255, then the alpha from 0 to 1.
     */
    private function parse_colour(string $colour): array {
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $colour, $matches)) {
            $hex = strlen($matches[1]) === 3 ? preg_replace('/(.)/', '$1$1', $matches[1]) : $matches[1];
            return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), 1.0];
        }
        if (preg_match('/^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*(?:,\s*([\d.]+)\s*)?\)$/', $colour, $matches)) {
            return [(float) $matches[1], (float) $matches[2], (float) $matches[3], (float) ($matches[4] ?? 1)];
        }
        $this->fail("Unexpected colour format: {$colour}");
    }

    /**
     * Blend a translucent colour into an opaque background.
     *
     * @param float[] $colour The colour, as returned by parse_colour().
     * @param float[] $background The background, as returned by parse_colour().
     * @return float[] The opaque result.
     */
    private function blend(array $colour, array $background): array {
        $alpha = $colour[3];
        return [
            $colour[0] * $alpha + $background[0] * (1 - $alpha),
            $colour[1] * $alpha + $background[1] * (1 - $alpha),
            $colour[2] * $alpha + $background[2] * (1 - $alpha),
            1.0,
        ];
    }

    /**
     * The WCAG contrast ratio of two opaque colours.
     *
     * @param float[] $first A colour, as returned by parse_colour().
     * @param float[] $second A colour, as returned by parse_colour().
     * @return float
     */
    private function contrast(array $first, array $second): float {
        $luminance = static function (array $colour): float {
            $channels = array_map(static function (float $channel): float {
                $channel /= 255;
                return $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
            }, array_slice($colour, 0, 3));
            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };
        $lighter = max($luminance($first), $luminance($second));
        $darker = min($luminance($first), $luminance($second));
        return ($lighter + 0.05) / ($darker + 0.05);
    }
}

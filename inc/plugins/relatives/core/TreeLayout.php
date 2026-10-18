<?php
/**
 * relatives - TreeLayout
 *
 * Horizontal positions for the family tree (rows = generations are fixed beforehand).
 *
 * The tree is laid out as an hourglass built from blocks, from the root outwards:
 *
 * - A unit is a person with all partners of the same generation (built by
 *   Relatives_TreeBuilder::build_units()); a unit is never torn apart.
 * - desc(U) is the block of U and everything below it: the children of U's families side
 *   by side (siblings by year of birth), each with its own block, packed by their contours
 *   (Reingold-Tilford). The parents stand centred above their children. Widths therefore
 *   travel from the bottom up -- the younger rows decide, the older rows follow.
 * - Above the root the tree climbs family by family. A couple is a seam: the siblings of
 *   the left partner stand to the left (the partner at the right edge of their sibling
 *   group), the siblings of the right partner to the right. Each sibling brings its own
 *   block. The parents are placed centred above their children, or as close as the row
 *   allows -- pushed outwards when two ancestor couples meet above a seam.
 * - Families are climbed in order of their distance from the root, so the rows close to the
 *   root are filled first. Older collateral lines that no longer fit beside their siblings
 *   move to the next free place of their rows (as in a hand-drawn chart).
 *
 * Pure computation without database access (tests/cli-tree-layout.php).
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_TreeLayout
{
    /** Extra space between two units of the same sibling group. */
    public const SIBLING_GAP = 0.14;

    /** Extra space between units of different families. */
    public const UNIT_GAP = 0.6;

    private const EPSILON = 0.0001;

    private string $root;

    /** key => ['generation' => int, 'birth_year' => ?int] */
    private array $nodes;

    /** key => insertion index (stable tie-break) */
    private array $rank = [];

    /** unit_id => ['members' => [key, ...], 'generation' => int] */
    private array $units;

    /** key => unit_id */
    private array $unit_of = [];

    /** family_id => family (layout families: positioning parents/children) */
    private array $families = [];

    /** family_id => unit_id of its parents */
    private array $parent_unit = [];

    /** unit_id => [family_id, ...] -- families whose parents form this unit */
    private array $child_families = [];

    /** key => family_id the person is a child of */
    private array $origin = [];

    /** Secondary families (adoptive, step, foster): only used to place parents left over. */
    private array $secondary;

    private array $links;

    /** floating key => anchor key (loose node standing beside its anchor in a sibling group) */
    private array $floating;

    /** unit_id => centre x */
    private array $x = [];

    /** generation => [[left, right, unit_id], ...] */
    private array $rows = [];

    private array $placed = [];

    /** unit_id => -1 (grows to the left), 1 (to the right), 0 (centre) */
    private array $outward = [];

    private array $expanded = [];

    /** unit_id => sort key; units waiting to have their origin families climbed */
    private array $queue = [];

    /** unit_id => distance from the root unit */
    private array $distance = [];

    /**
     * @param array  $nodes     key => ['generation' => int, 'birth_year' => ?int]
     * @param array  $units     unit_id => ['members' => [...], 'generation' => int]
     * @param array  $families  layout families (id, parents, children)
     * @param array  $secondary secondary families (id, parents, children)
     * @param array  $links     [['from' => key, 'to' => key], ...]
     * @param array  $floating  floating key => anchor key
     */
    public function __construct(string $root, array $nodes, array $units, array $families, array $secondary = [], array $links = [], array $floating = [])
    {
        $this->root = $root;
        $this->nodes = $nodes;
        $this->units = $units;
        $this->secondary = $secondary;
        $this->links = $links;
        $this->floating = $floating;

        $index = 0;
        foreach (array_keys($nodes) as $key) {
            $this->rank[$key] = $index++;
        }
        foreach ($units as $unit_id => $unit) {
            foreach ($unit['members'] as $member) {
                $this->unit_of[$member] = $unit_id;
            }
        }

        foreach ($families as $family) {
            $id = (string)$family['id'];
            $this->families[$id] = $family;
            $parent_unit = $this->closest_parent_unit($family['parents']);
            if ($parent_unit !== null) {
                $this->parent_unit[$id] = $parent_unit;
                $this->child_families[$parent_unit][] = $id;
            }
            foreach ($family['children'] as $child) {
                if (!isset($this->origin[$child]) && isset($this->unit_of[$child])) {
                    $this->origin[$child] = $id;
                }
            }
        }
    }

    /**
     * @return array unit_id => centre x
     */
    public function run(): array
    {
        $root_unit = $this->unit_of[$this->root] ?? null;
        if ($root_unit === null) {
            return [];
        }
        $this->distance = $this->distances($root_unit);

        $this->commit($this->desc($root_unit, 0), 0.0);
        while (true) {
            while ($this->queue) {
                asort($this->queue);
                $unit_id = (string)array_key_first($this->queue);
                unset($this->queue[$unit_id]);
                $this->climb($unit_id);
            }
            if (!$this->place_leftover()) {
                break;
            }
        }
        return $this->x;
    }

    /** A unit after run() -- its members may have been reversed to face outwards. */
    public function unit(string $unit_id): array
    {
        return $this->units[$unit_id];
    }

    // --- Blocks ----------------------------------------------------------------------

    /**
     * Block of $unit_id and everything below it, positions relative to the block.
     *
     * @return array ['x' => [unit_id => x], 'min' => [generation => x], 'max' => [generation => x]]
     */
    private function desc(string $unit_id, int $outward): array
    {
        $this->mark_placed($unit_id, $outward);
        $generation = $this->units[$unit_id]['generation'];

        $groups = [];
        foreach ($this->child_families[$unit_id] ?? [] as $family_id) {
            $kids = [];
            foreach ($this->families[$family_id]['children'] as $child) {
                $child_unit = $this->unit_of[$child] ?? null;
                if ($child_unit === null || isset($this->placed[$child_unit]) || in_array($child_unit, $kids, true)
                    || $this->units[$child_unit]['generation'] <= $generation) {
                    continue;
                }
                $kids[] = $child_unit;
            }
            if (!$kids) {
                continue;
            }
            $kids = $this->sort_siblings($kids, $family_id);
            foreach ($kids as $kid) {
                $this->placed[$kid] = true; // reserved, desc() below fills it in
            }
            $groups[] = [$family_id, $kids, $this->pair_offset($unit_id, $family_id)];
        }
        usort($groups, static function ($a, $b) {
            return $a[2] <=> $b[2];
        });

        $all = [];
        foreach ($groups as [$family_id, $kids]) {
            foreach ($kids as $kid) {
                $all[] = [$family_id, $kid];
            }
        }
        $middle = (count($all) - 1) / 2;

        $block = null;
        $previous_family = null;
        foreach ($all as $index => [$family_id, $kid]) {
            $side = $index < $middle - self::EPSILON ? -1 : ($index > $middle + self::EPSILON ? 1 : ($outward !== 0 ? $outward : 1));
            $this->orient_spouses_outward($kid, $family_id, $side);
            unset($this->placed[$kid]);
            $kid_block = $this->desc($kid, $side);
            if ($block === null) {
                $block = $kid_block;
            } else {
                $sibling_row = $previous_family === $family_id ? $this->units[$kid]['generation'] : null;
                $block = self::merge($block, $kid_block, $this->pack_shift($block, $kid_block, $sibling_row));
            }
            $previous_family = $family_id;
        }

        if ($block === null) {
            return $this->unit_block($unit_id, 0.0);
        }

        // Parents centred above their children; with several families (a person with
        // children from two partners) the mean of the per-family offsets.
        $wishes = [];
        foreach ($groups as [$family_id]) {
            $center = $this->children_center($family_id, $block['x']);
            if ($center !== null) {
                $wishes[] = $center - $this->pair_offset($unit_id, $family_id);
            }
        }
        $x = $wishes ? array_sum($wishes) / count($wishes) : 0.0;
        return self::merge($block, $this->unit_block($unit_id, $x), 0.0);
    }

    /** A block holding one unit at $x. */
    private function unit_block(string $unit_id, float $x): array
    {
        [$left, $right] = $this->interval($unit_id, $x);
        $generation = $this->units[$unit_id]['generation'];
        return ['x' => [$unit_id => $x], 'min' => [$generation => $left], 'max' => [$generation => $right]];
    }

    /** Smallest shift that puts $right beside $left without overlapping in any row. */
    private function pack_shift(array $left, array $right, ?int $sibling_row): float
    {
        $shift = -INF;
        foreach ($right['min'] as $generation => $min) {
            if (!isset($left['max'][$generation])) {
                continue;
            }
            $gap = $generation === $sibling_row ? self::SIBLING_GAP : self::UNIT_GAP;
            $shift = max($shift, $left['max'][$generation] + $gap - $min);
        }
        if ($shift === -INF) {
            $shift = max($left['max']) + self::UNIT_GAP - min($right['min']);
        }
        return $shift;
    }

    private static function merge(array $a, array $b, float $shift): array
    {
        foreach ($b['x'] as $unit_id => $x) {
            $a['x'][$unit_id] = $x + $shift;
        }
        foreach ($b['min'] as $generation => $min) {
            $a['min'][$generation] = min($a['min'][$generation] ?? INF, $min + $shift);
        }
        foreach ($b['max'] as $generation => $max) {
            $a['max'][$generation] = max($a['max'][$generation] ?? -INF, $max + $shift);
        }
        return $a;
    }

    // --- Climbing ----------------------------------------------------------------------

    /** Lays out the families the members of a placed unit come from. */
    private function climb(string $unit_id): void
    {
        $members = $this->units[$unit_id]['members'];
        $count = count($members);
        $middle = ($count - 1) / 2;
        $outward = $this->outward[$unit_id] ?? 0;
        foreach ($members as $index => $member) {
            $family_id = $this->origin[$member] ?? null;
            if ($family_id === null || isset($this->expanded[$family_id])) {
                continue;
            }
            if ($count === 1) {
                $side = $member === $this->root ? 0 : $outward;
            } else {
                $side = $index < $middle - self::EPSILON ? -1 : ($index > $middle + self::EPSILON ? 1 : $outward);
            }
            $this->expand($family_id, $unit_id, $side);
        }
    }

    /**
     * Places the siblings of $anchor (a placed unit) and the parents of $family_id.
     * $side: -1 = siblings go to the left (anchor at the right edge), 1 = to the right,
     * 0 = around the anchor by year of birth.
     */
    private function expand(string $family_id, string $anchor, int $side): void
    {
        $this->expanded[$family_id] = true;
        $parent_unit = $this->parent_unit[$family_id] ?? null;

        // Siblings of the anchor's own family, then half-siblings from other families of
        // the same parents (further out, on the side of their parent pair).
        $own = [];
        foreach ($this->families[$family_id]['children'] as $child) {
            $child_unit = $this->unit_of[$child] ?? null;
            if ($child_unit !== null && ($child_unit === $anchor || !isset($this->placed[$child_unit]))
                && !in_array($child_unit, $own, true)) {
                $own[] = $child_unit;
            }
        }
        if (!in_array($anchor, $own, true)) {
            $own[] = $anchor;
        }
        $own = $this->sort_siblings($own, $family_id);
        // A root without a known age stands in the middle of its siblings, not at an edge.
        if ($side === 0 && $this->unit_birth($anchor, $family_id) === null && !in_array($anchor, $this->floating, true)
            && !in_array($this->units[$anchor]['members'][0] ?? '', $this->floating, true)) {
            $own = array_values(array_diff($own, [$anchor]));
            array_splice($own, intdiv(count($own) + 1, 2), 0, [$anchor]);
        }
        $position = (int)array_search($anchor, $own, true);
        if ($side < 0) {
            $left = array_values(array_diff($own, [$anchor]));
            $right = [];
        } elseif ($side > 0) {
            $left = [];
            $right = array_values(array_diff($own, [$anchor]));
        } else {
            $left = array_slice($own, 0, $position);
            $right = array_slice($own, $position + 1);
        }
        $left_groups = [[$family_id, array_reverse($left)]];
        $right_groups = [[$family_id, $right]];

        if ($parent_unit !== null) {
            $own_offset = $this->pair_offset($parent_unit, $family_id);
            foreach ($this->child_families[$parent_unit] ?? [] as $other) {
                if ($other === $family_id || isset($this->expanded[$other])) {
                    continue;
                }
                $kids = [];
                foreach ($this->families[$other]['children'] as $child) {
                    $child_unit = $this->unit_of[$child] ?? null;
                    if ($child_unit !== null && !isset($this->placed[$child_unit]) && !in_array($child_unit, $kids, true)) {
                        $kids[] = $child_unit;
                    }
                }
                $this->expanded[$other] = true;
                if (!$kids) {
                    continue;
                }
                $kids = $this->sort_siblings($kids, $other);
                $goes_left = $side < 0 || ($side === 0 && $this->pair_offset($parent_unit, $other) < $own_offset);
                if ($goes_left) {
                    $left_groups[] = [$other, array_reverse($kids)];
                } else {
                    $right_groups[] = [$other, $kids];
                }
            }
        }

        $this->place_siblings($anchor, $left_groups, -1);
        $this->place_siblings($anchor, $right_groups, 1);

        if ($parent_unit === null || isset($this->placed[$parent_unit])) {
            return;
        }
        $outward = $side !== 0 ? $side : ($this->outward[$anchor] ?? 0);
        $this->orient_toward($parent_unit, $outward);
        $wishes = [];
        foreach ($this->child_families[$parent_unit] ?? [] as $child_family) {
            $center = $this->children_center($child_family, $this->x);
            if ($center !== null) {
                $wishes[] = $center - $this->pair_offset($parent_unit, $child_family);
            }
        }
        $desired = $wishes ? array_sum($wishes) / count($wishes) : ($this->x[$anchor] ?? 0.0);
        $block = $this->unit_block($parent_unit, 0.0);
        $this->commit($block, $this->find_shift($block, $desired, 0, null, $outward));
        $this->mark_placed($parent_unit, $outward);
    }

    /**
     * Sibling blocks next to $anchor, nearest first, growing in $direction. Each one goes
     * to the closest free place beyond what is already there.
     */
    private function place_siblings(string $anchor, array $groups, int $direction): void
    {
        $edge_unit = $anchor;
        $edge_family = null;
        foreach ($groups as [$family_id, $kids]) {
            foreach ($kids as $kid) {
                if (isset($this->placed[$kid])) {
                    continue;
                }
                $this->orient_spouses_outward($kid, $family_id, $direction);
                $block = $this->desc($kid, $direction);
                $generation = $this->units[$kid]['generation'];
                $same_family = $edge_family === null || $edge_family === $family_id;
                $gap = $same_family ? self::SIBLING_GAP : self::UNIT_GAP;

                [$edge_left, $edge_right] = $this->interval($edge_unit, $this->x[$edge_unit]);
                [$kid_left, $kid_right] = $this->interval($kid, $block['x'][$kid]);
                $desired = $direction < 0 ? $edge_left - $gap - $kid_right : $edge_right + $gap - $kid_left;
                $sibling_row = $same_family && $this->units[$edge_unit]['generation'] === $generation ? $generation : null;

                $this->commit($block, $this->find_shift($block, $desired, $direction, $sibling_row, $direction));
                $edge_unit = $kid;
                $edge_family = $family_id;
            }
        }
    }

    // --- Leftovers -------------------------------------------------------------------

    /**
     * Places one unit that the climbing did not reach (parents of an adopted child, nodes
     * only tied by a loose line, separate islands) next to what it is connected to.
     *
     * @return bool false when everything is placed
     */
    private function place_leftover(): bool
    {
        $candidates = [];
        foreach ($this->units as $unit_id => $unit) {
            if (!isset($this->placed[$unit_id])) {
                $candidates[$unit_id] = [$this->distance[$unit_id] ?? PHP_INT_MAX, $this->unit_rank($unit_id)];
            }
        }
        if (!$candidates) {
            return false;
        }
        asort($candidates);
        $unit_id = (string)array_key_first($candidates);
        $members = array_flip($this->units[$unit_id]['members']);

        // Parent of placed children (secondary family, or parents in another row).
        $children_xs = [];
        foreach (array_merge($this->families, $this->secondary) as $family) {
            $is_parent = false;
            foreach ($family['parents'] as $parent) {
                if (isset($members[$parent])) {
                    $is_parent = true;
                }
            }
            if (!$is_parent) {
                continue;
            }
            foreach ($family['children'] as $child) {
                $child_unit = $this->unit_of[$child] ?? null;
                if ($child_unit !== null && isset($this->x[$child_unit])) {
                    $children_xs[] = $this->member_x($child);
                }
            }
        }

        // Child of placed parents.
        $parent_xs = [];
        foreach (array_merge($this->families, $this->secondary) as $family) {
            $is_child = false;
            foreach ($family['children'] as $child) {
                if (isset($members[$child])) {
                    $is_child = true;
                }
            }
            if (!$is_child) {
                continue;
            }
            foreach ($family['parents'] as $parent) {
                $parent_unit = $this->unit_of[$parent] ?? null;
                if ($parent_unit !== null && isset($this->x[$parent_unit])) {
                    $parent_xs[] = $this->member_x($parent);
                }
            }
        }

        // Sibling of placed children of the same family.
        $sibling_xs = [];
        foreach ($this->families as $family) {
            if (!array_intersect($family['children'], array_keys($members))) {
                continue;
            }
            foreach ($family['children'] as $child) {
                $child_unit = $this->unit_of[$child] ?? null;
                if ($child_unit !== null && isset($this->x[$child_unit])) {
                    $sibling_xs[] = $this->member_x($child);
                }
            }
        }

        // Loose line to a placed node.
        $link_xs = [];
        foreach ($this->links as $link) {
            foreach ([[$link['from'], $link['to']], [$link['to'], $link['from']]] as [$own, $other]) {
                $other_unit = $this->unit_of[$other] ?? null;
                if (isset($members[$own]) && $other_unit !== null && isset($this->x[$other_unit])) {
                    $link_xs[] = $this->member_x($other);
                }
            }
        }

        $block = $this->desc($unit_id, 0);
        $offset = $block['x'][$unit_id];
        foreach ([$children_xs, $parent_xs, $sibling_xs, $link_xs] as $xs) {
            if ($xs) {
                $desired = (min($xs) + max($xs)) / 2 - $offset;
                $this->commit($block, $this->find_shift($block, $desired, 0, null, 1));
                return true;
            }
        }

        // Island: to the right of everything.
        $right = -INF;
        foreach ($this->rows as $intervals) {
            foreach ($intervals as $interval) {
                $right = max($right, $interval[1]);
            }
        }
        $desired = ($right === -INF ? 0.0 : $right + self::UNIT_GAP) - min($block['min']);
        $this->commit($block, $this->find_shift($block, $desired, 1, null, 1));
        return true;
    }

    // --- Global rows -----------------------------------------------------------------

    /**
     * Shift closest to $desired at which $block overlaps nothing placed so far.
     * $direction: -1 only shifts <= desired, 1 only >= desired, 0 both (ties go $prefer).
     */
    private function find_shift(array $block, float $desired, int $direction, ?int $sibling_row, int $prefer): float
    {
        $candidates = [$desired];
        $intervals = [];
        foreach ($block['x'] as $unit_id => $x) {
            $generation = $this->units[$unit_id]['generation'];
            [$left, $right] = $this->interval($unit_id, $x);
            $intervals[] = [$generation, $left, $right];
            $gap = $generation === $sibling_row ? self::SIBLING_GAP : self::UNIT_GAP;
            foreach ($this->rows[$generation] ?? [] as [$row_left, $row_right]) {
                $candidates[] = $row_left - $gap - $right;
                $candidates[] = $row_right + $gap - $left;
            }
        }

        $filtered = [];
        foreach ($candidates as $candidate) {
            if (($direction < 0 && $candidate > $desired + self::EPSILON) || ($direction > 0 && $candidate < $desired - self::EPSILON)) {
                continue;
            }
            $filtered[(string)round($candidate, 6)] = $candidate;
        }
        $filtered = array_values($filtered);
        usort($filtered, static function ($a, $b) use ($desired, $prefer) {
            $distance = abs($a - $desired) <=> abs($b - $desired);
            if ($distance !== 0 && abs(abs($a - $desired) - abs($b - $desired)) > self::EPSILON) {
                return $distance;
            }
            return (($b - $desired) * $prefer) <=> (($a - $desired) * $prefer);
        });

        foreach ($filtered as $shift) {
            if ($this->fits($intervals, $shift, $sibling_row)) {
                return $shift;
            }
        }
        return $desired; // not reachable: the outermost candidate always fits
    }

    private function fits(array $intervals, float $shift, ?int $sibling_row): bool
    {
        foreach ($intervals as [$generation, $left, $right]) {
            $gap = $generation === $sibling_row ? self::SIBLING_GAP : self::UNIT_GAP;
            foreach ($this->rows[$generation] ?? [] as [$row_left, $row_right]) {
                if ($left + $shift < $row_right + $gap - self::EPSILON && $right + $shift > $row_left - $gap + self::EPSILON) {
                    return false;
                }
            }
        }
        return true;
    }

    private function commit(array $block, float $shift): void
    {
        foreach ($block['x'] as $unit_id => $x) {
            $this->x[$unit_id] = $x + $shift;
            [$left, $right] = $this->interval($unit_id, $x + $shift);
            $this->rows[$this->units[$unit_id]['generation']][] = [$left, $right, $unit_id];
        }
    }

    // --- Helpers ---------------------------------------------------------------------

    private function mark_placed(string $unit_id, int $outward): void
    {
        $this->placed[$unit_id] = true;
        $this->outward[$unit_id] = $outward;
        $this->queue[$unit_id] = [$this->distance[$unit_id] ?? PHP_INT_MAX, $this->unit_rank($unit_id)];
    }

    /** [left, right] edge of a unit centred at $x (one node is one unit wide). */
    private function interval(string $unit_id, float $x): array
    {
        $half = (count($this->units[$unit_id]['members']) - 1) / 2 + 0.5;
        return [$x - $half, $x + $half];
    }

    private function member_offset(string $key): float
    {
        $unit_id = $this->unit_of[$key];
        $members = $this->units[$unit_id]['members'];
        return (int)array_search($key, $members, true) - (count($members) - 1) / 2;
    }

    private function member_x(string $key): float
    {
        return $this->x[$this->unit_of[$key]] + $this->member_offset($key);
    }

    /** Offset of a family's parents (mean) from the centre of their unit. */
    private function pair_offset(string $unit_id, string $family_id): float
    {
        $offsets = [];
        foreach ($this->families[$family_id]['parents'] as $parent) {
            if (($this->unit_of[$parent] ?? null) === $unit_id) {
                $offsets[] = $this->member_offset($parent);
            }
        }
        return $offsets ? array_sum($offsets) / count($offsets) : 0.0;
    }

    /** Centre of the span of a family's children, positions from $xs (unit_id => x). */
    private function children_center(string $family_id, array $xs): ?float
    {
        $min = INF;
        $max = -INF;
        foreach ($this->families[$family_id]['children'] as $child) {
            $child_unit = $this->unit_of[$child] ?? null;
            if ($child_unit === null || !isset($xs[$child_unit])) {
                continue;
            }
            $x = $xs[$child_unit] + $this->member_offset($child);
            $min = min($min, $x);
            $max = max($max, $x);
        }
        return $min === INF ? null : ($min + $max) / 2;
    }

    /**
     * Siblings by year of birth. Siblings without one keep their place in the order of
     * entry; only those with a year swap places among each other. A node standing loosely
     * beside a sibling (friend, adoptive sibling without parents) goes directly next to it,
     * and that sibling to the nearer edge of the group.
     */
    private function sort_siblings(array $unit_ids, string $family_id): array
    {
        $rank = [];
        foreach ($unit_ids as $unit_id) {
            $rank[$unit_id] = $this->unit_rank($unit_id);
        }
        usort($unit_ids, static function ($a, $b) use ($rank) {
            return $rank[$a] <=> $rank[$b];
        });
        $slots = [];
        $known = [];
        foreach ($unit_ids as $index => $unit_id) {
            $birth = $this->unit_birth($unit_id, $family_id);
            if ($birth !== null) {
                $slots[] = $index;
                $known[] = [$birth, $rank[$unit_id], $unit_id];
            }
        }
        sort($known);
        foreach ($slots as $position => $index) {
            $unit_ids[$index] = $known[$position][2];
        }

        $floats_of = [];
        $floating_units = [];
        foreach ($this->floating as $floating => $anchor) {
            $float_unit = $this->unit_of[$floating] ?? null;
            $anchor_unit = $this->unit_of[$anchor] ?? null;
            if ($float_unit !== null && $anchor_unit !== null && $float_unit !== $anchor_unit
                && in_array($float_unit, $unit_ids, true) && in_array($anchor_unit, $unit_ids, true)) {
                $floats_of[$anchor_unit][] = $float_unit;
                $floating_units[$float_unit] = true;
            }
        }
        if (!$floats_of) {
            return $unit_ids;
        }
        $rest = array_values(array_filter($unit_ids, static function ($unit_id) use ($floating_units) {
            return !isset($floating_units[$unit_id]);
        }));
        foreach ($floats_of as $anchor_unit => $floats) {
            $index = (int)array_search($anchor_unit, $rest, true);
            array_splice($rest, $index, 1);
            if ($index < count($rest) / 2) {
                $rest = array_merge(array_reverse($floats), [$anchor_unit], $rest);
            } else {
                $rest = array_merge($rest, [$anchor_unit], $floats);
            }
        }
        return $rest;
    }

    /** Earliest year of birth of the unit's members who are children of $family_id. */
    private function unit_birth(string $unit_id, string $family_id): ?int
    {
        $children = array_flip($this->families[$family_id]['children']);
        $members = $this->units[$unit_id]['members'];
        $birth = null;
        foreach ($members as $member) {
            if (!isset($children[$member]) && count($members) > 1) {
                continue;
            }
            $year = $this->nodes[$member]['birth_year'] ?? null;
            if ($year !== null) {
                $birth = $birth === null ? (int)$year : min($birth, (int)$year);
            }
        }
        return $birth;
    }

    /** Partners who are not children of $family_id stand on the $side of the unit. */
    private function orient_spouses_outward(string $unit_id, string $family_id, int $side): void
    {
        $children = array_flip($this->families[$family_id]['children']);
        $this->orient($unit_id, static function (string $member) use ($children): bool {
            return !isset($children[$member]);
        }, $side);
    }

    /** Members whose origin family is still open stand on the $side of the unit. */
    private function orient_toward(string $unit_id, int $side): void
    {
        $this->orient($unit_id, function (string $member): bool {
            $family_id = $this->origin[$member] ?? null;
            return $family_id !== null && !isset($this->expanded[$family_id]);
        }, $side);
    }

    /** Reverses the unit when the members matching $outer lean to the wrong side. */
    private function orient(string $unit_id, callable $outer, int $side): void
    {
        $members = $this->units[$unit_id]['members'];
        if ($side === 0 || count($members) < 2) {
            return;
        }
        $outer_sum = 0.0;
        $outer_count = 0;
        $inner_sum = 0.0;
        $inner_count = 0;
        foreach ($members as $index => $member) {
            if ($outer($member)) {
                $outer_sum += $index;
                $outer_count++;
            } else {
                $inner_sum += $index;
                $inner_count++;
            }
        }
        if ($outer_count === 0 || $inner_count === 0) {
            return;
        }
        $lean = $outer_sum / $outer_count - $inner_sum / $inner_count;
        if (($side < 0 && $lean > 0) || ($side > 0 && $lean < 0)) {
            $this->units[$unit_id]['members'] = array_reverse($members);
        }
    }

    /** Unit of the parent closest above the children (parents can sit in two rows). */
    private function closest_parent_unit(array $parents): ?string
    {
        $best = null;
        foreach ($parents as $parent) {
            $unit_id = $this->unit_of[$parent] ?? null;
            if ($unit_id !== null && ($best === null || $this->units[$unit_id]['generation'] > $this->units[$best]['generation'])) {
                $best = $unit_id;
            }
        }
        return $best;
    }

    private function unit_rank(string $unit_id): int
    {
        $rank = PHP_INT_MAX;
        foreach ($this->units[$unit_id]['members'] as $member) {
            $rank = min($rank, $this->rank[$member] ?? PHP_INT_MAX);
        }
        return $rank;
    }

    /** Breadth-first distance between units over families and loose lines. */
    private function distances(string $root_unit): array
    {
        $adjacent = [];
        $connect = function (array $keys) use (&$adjacent): void {
            $units = [];
            foreach ($keys as $key) {
                if (isset($this->unit_of[$key])) {
                    $units[$this->unit_of[$key]] = true;
                }
            }
            $units = array_keys($units);
            foreach ($units as $a) {
                foreach ($units as $b) {
                    if ($a !== $b) {
                        $adjacent[$a][$b] = true;
                    }
                }
            }
        };
        foreach (array_merge($this->families, $this->secondary) as $family) {
            $connect(array_merge($family['parents'], $family['children']));
        }
        foreach ($this->links as $link) {
            $connect([$link['from'], $link['to']]);
        }

        $distance = [$root_unit => 0];
        $queue = [$root_unit];
        while ($queue) {
            $unit_id = array_shift($queue);
            foreach (array_keys($adjacent[$unit_id] ?? []) as $next) {
                if (!isset($distance[$next])) {
                    $distance[$next] = $distance[$unit_id] + 1;
                    $queue[] = $next;
                }
            }
        }
        return $distance;
    }
}

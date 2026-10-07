<?php

define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__) . '/includes/class-civ-rest.php';

$service = new CIV_REST();
$method = new ReflectionMethod('CIV_REST', 'calculate_ranking');
if (PHP_VERSION_ID < 80100) {
    $method->setAccessible(true);
}

function assert_true($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function smoothed_win_rate_order($ids, $comparisons)
{
    $wins = array_fill_keys($ids, 0);
    $counts = array_fill_keys($ids, 0);
    foreach ($comparisons as $comparison) {
        $first = (int) $comparison->first_idea_id;
        $second = (int) $comparison->second_idea_id;
        $first_wins = (int) $comparison->first_wins;
        $total = (int) $comparison->total;
        $wins[$first] += $first_wins;
        $wins[$second] += $total - $first_wins;
        $counts[$first] += $total;
        $counts[$second] += $total;
    }
    $scores = array();
    foreach ($ids as $id) {
        $scores[$id] = ($wins[$id] + 1) / ($counts[$id] + 2);
    }
    uksort($scores, function ($first, $second) use ($scores) {
        if ($scores[$first] === $scores[$second]) {
            return $first <=> $second;
        }
        return $scores[$second] <=> $scores[$first];
    });
    return array_keys($scores);
}

$ideas = array(
    (object) array('id' => 1, 'idea_text' => 'Alpha'),
    (object) array('id' => 2, 'idea_text' => 'Beta'),
    (object) array('id' => 3, 'idea_text' => 'Gamma'),
);

$no_votes = $method->invoke($service, $ideas, array());
assert_true(count($no_votes) === 3, 'all active ideas are ranked without votes');
assert_true($no_votes[0]['score'] === 0.5 && $no_votes[1]['score'] === 0.5 && $no_votes[2]['score'] === 0.5, 'no-data ideas receive neutral scores');
assert_true($no_votes[0]['low_data'] === true && $no_votes[0]['comparisons'] === 0, 'no-data ideas are marked uncertain');

$comparisons = array();
$comparisons[] = (object) array('first_idea_id' => 1, 'second_idea_id' => 2, 'first_wins' => 12, 'total' => 12);
$comparisons[] = (object) array('first_idea_id' => 2, 'second_idea_id' => 3, 'first_wins' => 12, 'total' => 12);
$comparisons[] = (object) array('first_idea_id' => 1, 'second_idea_id' => 3, 'first_wins' => 12, 'total' => 12);
$ranking = $method->invoke($service, $ideas, $comparisons);
assert_true(array_column($ranking, 'id') === array(1, 2, 3), 'stronger pairwise results rank in order');
assert_true($ranking[0]['comparisons'] === 24 && $ranking[1]['comparisons'] === 24 && $ranking[2]['comparisons'] === 24, 'comparison counts include both sides of each vote');
assert_true($ranking[0]['low_data'] === false, 'ideas with sufficient comparisons are not flagged low-data');

$tie = array((object) array('first_idea_id' => 1, 'second_idea_id' => 2, 'first_wins' => 4, 'total' => 8));
$two_ideas = array_slice($ideas, 0, 2);
$tied_ranking = $method->invoke($service, $two_ideas, $tie);
assert_true($tied_ranking[0]['score'] === 0.5 && $tied_ranking[1]['score'] === 0.5, 'balanced results remain tied');

$sparse = array(
    (object) array('first_idea_id' => 1, 'second_idea_id' => 2, 'first_wins' => 1, 'total' => 1),
    (object) array('first_idea_id' => 2, 'second_idea_id' => 3, 'first_wins' => 1, 'total' => 1),
);
$sparse_ranking = $method->invoke($service, $ideas, $sparse);
assert_true(array_column($sparse_ranking, 'id') === array(1, 2, 3), 'sparse chain ranking agrees with the smoothed win-rate baseline');
assert_true(array_column($sparse_ranking, 'id') === smoothed_win_rate_order(array(1, 2, 3), $sparse), 'Bradley-Terry and smoothed win-rate baseline agree on the sparse chain fixture');
assert_true($sparse_ranking[0]['low_data'] && $sparse_ranking[1]['low_data'] && $sparse_ranking[2]['low_data'], 'sparse comparisons remain explicitly uncertain');

echo "Scoring fixtures passed.\n";

<?php
$c1 = \Drupal\canvas\Entity\Component::load('views_list.poc_fields_rows.page_1');
$c2 = \Drupal\canvas\Entity\Component::load('block.views_block.spike_entity_rows-block_1');
$n1 = $c1->normalizeForClientSide()->values;
$n2 = $c2->normalizeForClientSide()->values;
print "views_list keys: " . implode(',', array_keys($n1)) . "\n";
print "block keys:      " . implode(',', array_keys($n2)) . "\n";
print "missing vs block: " . implode(',', array_diff(array_keys($n2), array_keys($n1))) . "\n";
foreach (array_diff(array_keys($n2), array_keys($n1)) as $k) { print "  block[$k] = " . json_encode($n2[$k]) . "\n"; }

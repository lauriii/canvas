<?php
$c1 = \Drupal\canvas\Entity\Component::load('views_list.poc_fields_rows.page_1');
$c2 = \Drupal\canvas\Entity\Component::load('js.grid');
$n1 = $c1->normalizeForClientSide()->values;
$n2 = $c2->normalizeForClientSide()->values;
print "views_list keys: " . implode(',', array_keys($n1)) . "\n";
print "js.grid keys:    " . implode(',', array_keys($n2)) . "\n";
print "missing vs grid: " . implode(',', array_diff(array_keys($n2), array_keys($n1))) . "\n";
print "grid metadata: " . json_encode($n2['metadata'] ?? NULL) . "\n";
print "mine metadata: " . json_encode($n1['metadata'] ?? NULL) . "\n";
if (isset($n2['propSources'])) print "grid propSources keys: " . implode(',', array_keys($n2['propSources'])) . "\n";

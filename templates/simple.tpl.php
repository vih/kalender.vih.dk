<p class="calendar-description"><?php e($events->getSubTitle()); ?></p>

<table>
	<tr>
		<th>Uge</th>
		<th>Dato</th>
		<th>Foredrag</th>
		<th>Sted</th>
	</tr>
<?php foreach ($events as $event): ?>
    <?php
    $start_date = new DateTime(date('Y-m-d H:i:s', strtotime($event->when[0]->startTime)), new DateTimeZone($context->getTimeZone()));
    $end_date = new DateTime(date('Y-m-d H:i:s', strtotime($event->when[0]->endTime)), new DateTimeZone($context->getTimeZone()));
    ?>
	<tr>
		<td><?php e($start_date->format('W')); ?></td>
		<td><?php e(t($start_date->format('l'))); ?>, <?php e($start_date->format('d-m-Y, H:i')); ?>-<?php e($end_date->format('H:i'))?></td>
		<?php
		    $id = substr($event->id, strrpos($event->id, '/')+1);
		?>
		<td><a href="<?php e(url($id)); ?>"><?php e($event->title)?></a></td>
		<td><?php if (isset($event->where[0])) e($event->where[0]->valueString); ?></td>
	</tr>
<?php endforeach; ?>
</table>
<table>
	<caption>Publiseret til</caption>
	<?php foreach ($publishers as $publisher): ?>
	<tr>
		<td><img src="<?php e($publisher['image']); ?>" /></td>
		<td><?php e($publisher['name']); ?></td>
		<td><a href="<?php e($publisher['event_url']); ?>"><?php e($publisher['status']); ?></a></td>
	</tr>
	<?php endforeach; ?>
</table>
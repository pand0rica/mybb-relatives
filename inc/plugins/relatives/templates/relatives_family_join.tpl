<form action="relatives.php?action=family_join" method="post" class="relatives_family_join">
	<input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
	<input type="hidden" name="id" value="{$relatives_family_join_id}" />
	<input type="hidden" name="from" value="{$relatives_family_join_from}" />
	<h3>{$lang->relatives_family_join_title}</h3>
	<p class="relatives_hint">{$lang->relatives_family_join_hint}</p>
	{$relatives_family_join_closed_hint}
	<div class="relatives_field">
		<label for="relatives_family_join_person">{$lang->relatives_family_join_person}</label>
		<select id="relatives_family_join_person" name="person_id">{$relatives_family_join_options}</select>
	</div>
	<div class="relatives_field">
		<label><input type="checkbox" name="family_as_parent" value="1" /> {$lang->relatives_relation_family_as_parent}</label>
		<p class="relatives_hint">{$lang->relatives_relation_family_hint}</p>
	</div>
	<input type="submit" class="button" value="{$lang->relatives_family_join_submit}" />
</form>

<form action="relatives.php?action=link_request" method="post" class="relatives_link_request_form">
	<input type="hidden" name="id" value="{$relatives_link_person_id}" />
	<input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
	<label for="relatives_target_username">{$lang->relatives_field_link_username}</label>
	<div class="relatives_link_request_inputs">
		<input type="text" class="textbox" id="relatives_target_username" name="target_username" maxlength="255" />
		<input type="submit" class="button" value="{$lang->relatives_link_send_request}" />
	</div>
</form>
<link rel="stylesheet" href="{$mybb->asset_url}/jscripts/select2/select2.css?ver=1807" />
<script type="text/javascript" src="{$mybb->asset_url}/jscripts/select2/select2.min.js?ver=1806"></script>
<script type="text/javascript">
<!--
// Username autocomplete: MyBB's own select2 + xmlhttp.php?action=get_users (same as the PM form).
if (typeof use_xmlhttprequest !== "undefined" && use_xmlhttprequest == "1" && window.jQuery && jQuery.fn.select2) {
	if (window.MyBB && typeof MyBB.select2 === "function") { MyBB.select2(); }
	jQuery("#relatives_target_username").select2({
		placeholder: "{$lang->relatives_link_search_user}",
		minimumInputLength: 2,
		ajax: {
			url: "xmlhttp.php?action=get_users",
			dataType: "json",
			data: function (term) { return { query: term }; },
			results: function (data) { return { results: data }; }
		}
	});
}
// -->
</script>

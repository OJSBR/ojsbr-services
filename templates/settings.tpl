<script>
	$(function() {ldelim}
		$('#ojsbrServicesSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="ojsbrServicesSettingsForm" method="post" action="{$formAction|escape}">
	{csrf}
	{fbvFormArea id="ojsbrServicesSettings"}
		{fbvFormSection title="plugins.generic.ojsbrServices.settings.connectorUrl" description="plugins.generic.ojsbrServices.settings.connectorUrl.description"}
			{fbvElement type="text" id="connectorUrl" name="connectorUrl" value=$connectorUrl maxlength="255"}
		{/fbvFormSection}
		{fbvFormSection title="plugins.generic.ojsbrServices.settings.token" description="plugins.generic.ojsbrServices.settings.token.description"}
			{fbvElement type="text" id="token" name="token" value=$token password=true}
		{/fbvFormSection}
	{/fbvFormArea}
	{fbvFormButtons id="ojsbrServicesSettingsSubmit" hideCancel=true submitText="common.save"}
</form>

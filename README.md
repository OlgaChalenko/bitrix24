# bitrix24
Before starting synchronization, you need to set up webhooks, through which data is exchanged between Bitrix24 and the online store. You can do this in the “Applications” section of Bitrix24.

The module supports the following rights:
- Users (user)
- CRM. After the initial synchronization settings, you will have access to:
- Portal name
- User ID
- Secret code

After that, you should configure the module, namely: specify the name of the portal, user ID and secret code received from Bitrix24 (image above)

After that you will be able to:
- create a contact before creating a lead
- put the user responsible for contacts
- set the source when creating a contact
- set default contact type
- configure the advanced option “Correspondence table: Buyer group on the site - Contact type in Bitrix24”

Leads, in turn, are created only when registering on the page intended for this, or when placing an order, and also provided that standard Opencart methods are used.

Please also note that this module has increased resource requirements, which depend on the number of products in your online store. Make sure that the values ​of the max_execution_time and memory_limit variables set on your hosting are sufficient for this module to work

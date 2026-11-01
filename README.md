# Web form that sends information to a discord channel with a webhook

**About This Project:** This is a web form in php that sends information to a discord channel of your choosing with a webhook, in the example provided it is a registration form.

**This is released for educational/demonstration purposes, please use with caution and secure this web form if you use it for for your project or discord server.**


# Installation/Use 

To use, Replace the Channel Webhook and set a cooldown in seconds 
```
const WEBHOOK_FALLBACK = 'https://discord.com/api/webhooks/xxx/zzz';
const SUBMIT_COOLDOWN_SECONDS = 600; // cooldown in seconds, 600 is 10 minutes
```

Edit the form and embed information and styles to your preference. 

Then upload the form to a web host that supports PHP 7.4 (or above preferably) with the cURL extension.


## Screenshots

![App Screenshot](https://github.heretic.network/screenshots/webform-box.png)
![App Screenshot](https://github.heretic.network/screenshots/webform-write.png)
![App Screenshot](https://github.heretic.network/screenshots/webform-submitted.png)
![App Screenshot](https://github.heretic.network/screenshots/webform-posted.png)


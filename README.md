
- `client_id` – Your Facebook App ID  
- `client_secret` – Your Facebook App Secret  
- `fb_exchange_token` – The short-lived access token you want to exchange  

👉 This will return a long-lived token you can use for your API calls.
https://graph.facebook.com/oauth/access_token?client_id=YOUR_APP_ID&client_secret=YOUR_APP_SECRET&grant_type=fb_exchange_token&fb_exchange_token=YOUR_SHORT_LIVED_TOKEN
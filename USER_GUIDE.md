# GenMagic Product Photos: User Guide

GenMagic Product Photos adds a section to the Magento admin product page that makes a new photo of your product from its own base image, in a style you choose. You add the photos you like to the product media gallery.

The extension is free. Photos are paid from your own GenMagic balance.

## 1. Before you start

- Magento Open Source or Adobe Commerce 2.4.7 or later, PHP 8.2 to 8.5.
- A GenMagic account with an API key and some credit. Get a key at https://genmagic.co/get-api-key. Add credit at https://genmagic.co/pricing.

## 2. Install

Install with Composer:

```
composer require genmagic/module-product-photos
bin/magento module:enable GenMagic_ProductPhotos
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

Or unzip the package into `app/code/GenMagic/ProductPhotos` and run the same commands from `module:enable` on.

## 3. Add your API key

1. Go to **Stores > Configuration**. In the left menu, open the **GenMagic** tab and choose **Product Photos**.
2. Make sure **Enable GenMagic product photos** is **Yes**.
3. Paste your key into **GenMagic API key**. It is stored encrypted.
4. Click **Save Config**.

## 4. Make a photo

1. Go to **Catalog > Products** and open a product. It needs a base image (saved with the product).
2. Open the **GenMagic product photos** section.
3. Choose a **Style**:
   - **White studio**: clean white background, soft light.
   - **Lifestyle scene**: the product in a real-life setting.
   - **With a model**: a person using or wearing the product.
   - **Flat lay**: top-down view with props.
   - **Seasonal**: holiday setting.
4. Optionally write **Extra wishes** (up to 300 characters), for example "on a wooden table".
5. Click **Make photo** and wait. It can take up to a minute. When it is ready you see the photo, what it cost and your balance left.

Every style asks for the product itself to stay exactly as in your photo: same shape, colors, materials, label, logo and proportions. Always check the result before adding it.

## 5. Add it to the gallery

- Click **Add to product gallery** to put the photo in the product's media gallery. It is saved at once. Reload the page to see it in the gallery list.
- Tick **Also use it as the base, small and thumbnail image** only if you want the new photo to replace those roles. Otherwise they stay as they are.
- Click **Discard** to drop the photo without adding it.

## 6. Permissions

In **System > Permissions > User Roles > Role Resources**:

- **Make product photos with GenMagic** (under Catalog > Inventory > Products) lets a role use the section.
- **GenMagic Section** (under Stores > Settings > Configuration) lets a role open the settings and the key.

## 7. Troubleshooting

| You see | What to do |
| --- | --- |
| The section asks for an API key | Add the key under Stores > Configuration > GenMagic |
| "Set a base image first and save the product" | Upload a base image, save the product, then try again |
| "The product image is too large" | Use a base image under 2.9 MB |
| Your balance is too low | Add credit at https://genmagic.co/pricing |
| "Another image is still being generated" | Wait for the first photo to finish |
| No section on the page | Check that the extension is enabled and that your role has the permission above |

Messages from GenMagic are shown as GenMagic wrote them.

## 8. What is sent to GenMagic

When you click **Make photo**: the product's base image, the style prompt and your extra words, and your API key. The list of models is loaded without the key. No customer or order data is sent.

## 9. Support

hello@genmagic.co

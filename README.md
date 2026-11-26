# GenMagic Product Photos for Magento 2 and Adobe Commerce

Make a new photo of a product from the product's own base image, in a style you choose, right on the admin product edit page. Add the ones you like to the product media gallery.

- Module: `GenMagic_ProductPhotos`
- Composer package: `genmagic/module-product-photos`
- Version: 1.0.0
- License: MIT
- Requires: Magento Open Source or Adobe Commerce 2.4.7 or later, PHP 8.2, 8.3, 8.4 or 8.5
- Price: free. Photos are paid from your own GenMagic balance (see "Cost" below). There is no Magento billing.

## What it does

On the product edit page a new section, **GenMagic product photos**, lets you:

1. Pick a style: White studio, Lifestyle scene, With a model, Flat lay or Seasonal.
2. Optionally add a few words of your own (up to 300 characters).
3. Press **Make photo**. The base image of the product is read from your store's media directory and sent to GenMagic, which returns a new photo. Every style tells GenMagic to keep the product exactly as it is (shape, colors, materials, label, logo, proportions).
4. Look at the result and press **Add to product gallery**, or **Discard**.

An added photo is put in the media gallery only. The base, small and thumbnail roles are never changed unless you tick **Also use it as the base, small and thumbnail image** before adding.

The base image must be a JPEG, PNG, WebP or GIF under 2.9 MB.

## Installation

### With Composer (recommended)

```bash
composer require genmagic/module-product-photos
bin/magento module:enable GenMagic_ProductPhotos
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

### From the zip (app/code)

1. Unzip the package into `app/code/GenMagic/ProductPhotos` (the folder that contains `composer.json`, `registration.php` and `etc`).
2. Run the same commands as above, starting at `bin/magento module:enable GenMagic_ProductPhotos`.

No database tables are created, so there is no data to remove on uninstall.

## Configuration

1. Get an API key at https://genmagic.co/get-api-key and add credit at https://genmagic.co/pricing.
2. In the admin go to **Stores > Configuration > GenMagic > Product Photos**.
3. Set **Enable GenMagic product photos** to Yes and paste the key into **GenMagic API key**. The key is stored encrypted with Magento's own encrypted backend model.
4. Save the configuration.

Without a key, the product page section explains where to get one.

### Permissions

Two ACL resources are added:

- **Make product photos with GenMagic** (under Catalog > Inventory > Products): who sees the section and may make and add photos.
- **GenMagic Section** (under Stores > Settings > Configuration): who may open the settings.

## Usage

1. Open **Catalog > Products** and edit a product that already has a base image. Save a new product first.
2. Open **GenMagic product photos**, pick a style and press **Make photo**. It can take up to a minute.
3. Press **Add to product gallery**. The photo is saved to the gallery at once. Reload the page to see it in the gallery list.

If your GenMagic balance is too low, you see GenMagic's message and a link to https://genmagic.co/pricing.

## Cost

Each photo is charged by GenMagic to your own GenMagic balance. The cost and the balance left are shown after each photo. The picker shows the estimated price of each model.

## Data sent to GenMagic and why

Only when you press **Make photo**:

| Data | Why |
| --- | --- |
| The base image file of that product | It is the picture the new photo is made from |
| The style prompt and your optional words | They say what kind of photo to make |
| Your API key (as a Bearer token) | It identifies your GenMagic account and balance |
| The chosen model name | It says which model makes the photo |

Opening the section loads the list of available models from GenMagic without your key. No customer, order or other store data is sent. The generated photo is downloaded from GenMagic and kept as a temporary file in `pub/media/tmp/genmagic` until you add it (files older than a day are removed). Calls go from your server, not from the admin's browser.

## Support

hello@genmagic.co

## License

MIT. See `LICENSE`.

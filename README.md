# Magento shell script for bind simple products to configurable

Add script to shell/ dicrectory in your magento project.

Export only configurable products though standard Import/Export.

Run script and put path to file relative ```var/``` directory.

```
php shell/corrector.php --file export/your-configurable-products.csv
```

After you can take file from var/export/

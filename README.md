php artisan import:medical-records storage/app/imports/yourfile.xlsx

Bash to import all sheets

for i in $(seq 1 21); do
  echo "Importing $i.xlsx..."
  php artisan import:medical-records "storage/app/imports/questions/${i}.xlsx"
done


Assign all active questions to all students:

php artisan assign:all-questions


Only for a provider:

php artisan assign:all-questions --provider_id=5


Only for one category:

php artisan assign:all-questions --category_id=2


Dry run:

php artisan assign:all-questions --dry-run


Include inactive records:

php artisan assign:all-questions --include-inactive
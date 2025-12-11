# e-kolekcija demonstracinė svetainė

PHP + MySQL prototipas su registracija, prisijungimu, administratoriaus pultu ir naujienų valdymu.

## Paleidimas
1. Susikurkite duomenų bazę, pvz. `kolekcija`, ir paleiskite SQL struktūrą:
   ```sql
   SOURCE schema.sql;
   ```
2. Nustatykite prisijungimus `.env` faile projekto šaknyje (galite kopijuoti iš `.env.example`):
   ```bash
   cp .env.example .env
   # pakoreguokite DB_* reikšmes pagal savo MySQL
   ```
   Arba eksportuokite kintamuosius į aplinką:
   ```bash
   export DB_HOST=localhost
   export DB_NAME=kolekcija
   export DB_USER=root
   export DB_PASS="slaptazodis"
   ```
3. Paleiskite PHP serverį projekto aplanke:
   ```bash
   php -S localhost:8000
   ```
4. Registruokite naują vartotoją, tada administratoriaus rolę galite suteikti per "Vartotojų rolės" lentelę admin pulto lange.

## Pagrindinės funkcijos
- Juodai baltas dizainas su #f1f1f1 fonu ir animuotu hero vaizdu per visą plotį.
- Prisijungimas, registracija, atsijungimas.
- Administratoriaus pultas su hero tekstų, nuorodos, paveikslėlio ir naujienų kūrimu/redagavimu.
- Galimybė vartotoją paskirti administratoriumi.
- Naujienų sąrašas /news.php puslapyje.

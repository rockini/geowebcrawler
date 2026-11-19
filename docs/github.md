# Creare il repository su GitHub

## 1. Crea l'account e il repository

1. Se non hai un account, registrati su https://github.com/signup.
2. Vai su https://github.com/new e compila:
   - **Repository name**: `geowebcrawler`
   - **Public** (il progetto è open)
   - **Non** spuntare "Add a README", ".gitignore" o "license": il repository deve nascere vuoto, perché i file sono già pronti.
3. Premi **Create repository**.

## 2. Carica il codice

Hai due strade.

**A. Lo carico io.** Collega GitHub a Claude da https://claude.ai/connect-github e installa l'app Claude sul repository quando te lo chiede. Poi scrivimi il nome del repository (es. `tuonome/geowebcrawler`): lo aggancio e carico io il primo commit.

**B. Lo carichi tu.** Installa *GitHub Desktop* (https://desktop.github.com) oppure *Git for Windows* (https://git-scm.com/download/win), scompatta lo zip del progetto in una cartella e da quella cartella:

```
git init -b main
git add .
git commit -m "Struttura iniziale e schema del database"
git remote add origin https://github.com/TUONOME/geowebcrawler.git
git push -u origin main
```

Con GitHub Desktop: *File → Add local repository*, scegli la cartella, *Publish repository*.

## 3. Lavorare in locale

Per avere il codice sul PC, dentro la cartella servita da Apache:

```
git clone https://github.com/TUONOME/geowebcrawler.git
```

Per ricevere gli aggiornamenti successivi basta `git pull` (o *Fetch origin* in GitHub Desktop).

I file `config/config.php` e `config/sources.json` restano solo sul tuo PC: sono esclusi da `.gitignore` per non pubblicare password.

#!/bin/bash
# Installateur du dossier des factures du 76 (Mac).
# Surveille un dossier : chaque nouveau PDF ou photo de facture est envoyé au coût matière (le76.fr/cout-matiere).
set -u
CLE="__CLE__"
URL="__URL__"
NOM="Factures Le 76"
DIR="$HOME/Library/Application Support/Le76-Factures"
PLIST="$HOME/Library/LaunchAgents/fr.le76.factures.plist"
APP="$DIR/$NOM.app"

echo ""
echo "  Le 76 · dossier des factures"
echo "  ----------------------------"
DOSSIER=$(osascript -e 'POSIX path of (choose folder with prompt "Choisis le dossier où tu ranges tes factures (PDF ou photos) :")' 2>/dev/null) || { echo "  Annulé : aucun dossier choisi."; exit 1; }
DOSSIER="${DOSSIER%/}"
mkdir -p "$DIR" "$HOME/Library/LaunchAgents"

# Réglages
{
  printf 'DOSSIER=%q\n' "$DOSSIER"
  printf 'CLE=%q\n' "$CLE"
  printf 'URL=%q\n' "$URL"
} > "$DIR/reglages.sh"
chmod 600 "$DIR/reglages.sh"

# Script d'envoi
cat > "$DIR/envoyer.sh" <<'EOS'
#!/bin/bash
DIR="$HOME/Library/Application Support/Le76-Factures"
. "$DIR/reglages.sh"
LISTE="$DIR/envoyees.txt"; JOURNAL="$DIR/journal.txt"
touch "$LISTE"
find "$DIR" -maxdepth 1 -name verrou -mmin +30 -exec rmdir {} \; 2>/dev/null
mkdir "$DIR/verrou" 2>/dev/null || exit 0
trap 'rmdir "$DIR/verrou" 2>/dev/null' EXIT
find "$DOSSIER" -type f \( -iname '*.pdf' -o -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.png' \) -print0 2>>"$JOURNAL" |
while IFS= read -r -d '' f; do
  h=$(shasum "$f" | cut -d' ' -f1)
  grep -q "^$h" "$LISTE" && continue
  # fichier en cours de copie : on attend qu'il ne bouge plus
  t1=$(stat -f %z "$f"); sleep 2; t2=$(stat -f %z "$f"); [ "$t1" = "$t2" ] || continue
  r=$(curl -s -m 290 -F action=facture_deposer -F "cle=$CLE" -F "fichier=@$f" "$URL")
  case "$r" in
    *'"ok":true'*) echo "$h $(date '+%Y-%m-%d %H:%M') $(basename "$f")" >> "$LISTE" ;;
    *) echo "$(date '+%Y-%m-%d %H:%M') échec $(basename "$f") : ${r:0:200}" >> "$JOURNAL" ;;
  esac
done
tail -n 200 "$JOURNAL" > "$JOURNAL.tmp" 2>/dev/null && mv "$JOURNAL.tmp" "$JOURNAL"
EOS
chmod 700 "$DIR/envoyer.sh"

# Les factures déjà présentes ne sont pas renvoyées (elles sont déjà dans la mercuriale)
: > "$DIR/envoyees.txt.new"
find "$DOSSIER" -type f \( -iname '*.pdf' -o -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.png' \) -print0 2>/dev/null |
while IFS= read -r -d '' f; do echo "$(shasum "$f" | cut -d' ' -f1) déjà-là $(basename "$f")"; done >> "$DIR/envoyees.txt.new"
[ -f "$DIR/envoyees.txt" ] && cat "$DIR/envoyees.txt" >> "$DIR/envoyees.txt.new"
mv "$DIR/envoyees.txt.new" "$DIR/envoyees.txt"
DEJA=$(wc -l < "$DIR/envoyees.txt" | tr -d ' ')

# Petite appli : c'est elle qui lit le dossier (macOS lui demande l'autorisation une seule fois)
rm -rf "$APP"
osacompile -o "$APP" -e 'do shell script "/bin/bash " & quoted form of (POSIX path of (path to home folder) & "Library/Application Support/Le76-Factures/envoyer.sh")' >/dev/null
/usr/libexec/PlistBuddy -c "Add :LSUIElement bool true" "$APP/Contents/Info.plist" 2>/dev/null
codesign --force --sign - "$APP" >/dev/null 2>&1   # re-signe après la modification (sinon macOS peut refuser de la lancer)

# Lancement automatique : à chaque changement dans le dossier, et toutes les 15 minutes
cat > "$PLIST" <<EOP
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>fr.le76.factures</string>
  <key>ProgramArguments</key><array><string>/usr/bin/open</string><string>-g</string><string>-a</string><string>$APP</string></array>
  <key>WatchPaths</key><array><string>$DOSSIER</string></array>
  <key>StartInterval</key><integer>900</integer>
  <key>RunAtLoad</key><true/>
</dict></plist>
EOP
launchctl bootout "gui/$(id -u)/fr.le76.factures" 2>/dev/null
launchctl bootstrap "gui/$(id -u)" "$PLIST"
open -g -a "$APP"   # premier passage tout de suite (et demande d'autorisation)

echo ""
echo "  ✅ C'est installé."
echo "  Dossier surveillé : $DOSSIER"
echo "  $DEJA facture(s) déjà présente(s) : elles ne seront pas renvoyées."
echo "  Si macOS demande si « $NOM » peut accéder au dossier, réponds Autoriser."
echo ""

# Trainer photographs

`tarryn-norris.jpg` and `fiston-nselike.jpg` are in, cleared for use 25 Aug 2026.
Both are 400x400, which is under the 600px asked for below but comfortably enough
for a card whose image column is 180px wide.

Fiston’s is the photograph HE supplied. He was asked for his LinkedIn one and
replied asking to send a different one instead — so this is that one, and his
LinkedIn is still not to be used for anything.

Anyone without a file here falls back to their initials on a brand-coloured tile,
which looks deliberate rather than broken.

## Adding one

1. Save the file here as `firstname-surname.jpg`, lowercase, hyphenated —
   `tarryn-norris.jpg`. Same convention as `images/graduates/`.
2. Add the path to that person's entry in `trainers.js`:

   ```js
   photo: 'images/trainers/tarryn-norris.jpg',
   ```

3. `trainers.js` is on the sync manifest, so that one edit reaches every
   academy. **The image file is not** — copy it into `images/trainers/` in each
   repo, or four sites will ask for a picture that is not there. A missing file
   falls back to the initials rather than showing a broken image, so this fails
   softly, but it still fails.

## What to ask for

Portrait orientation, head and shoulders, at least 600px on the short edge. The
card crops to a tall rectangle, so a wide group photo will lose most of the
person. Ask whether they are happy for that specific photograph to be published,
not just for "a photo" — it is their face on five public websites.

<article class="card mx-auto max-w-3xl space-y-6 text-sm leading-relaxed">
  <header>
    <h1 class="text-2xl font-bold">Datenschutzerklärung</h1>
    <p class="mt-1 text-slate-500">Stand: <?= $e($p['version']) ?> · Informationen nach Art. 13 und 14 DSGVO für die Nutzung von <?= $e($appName) ?></p>
  </header>

  <section>
    <h2 class="section-title">1. Verantwortlicher</h2>
    <?php if ($p['controller'] !== ''): ?>
      <p class="mt-2"><?= nl2br($e($p['controller'])) ?></p>
    <?php else: ?>
      <p class="alert alert-warn mt-2">Angaben zum Verantwortlichen sind noch nicht hinterlegt (Admin → Branding → Datenschutz).</p>
    <?php endif; ?>
    <?php if ($p['email'] !== ''): ?><p class="mt-2">Kontakt für Datenschutzanfragen: <a class="link" href="mailto:<?= $e($p['email']) ?>"><?= $e($p['email']) ?></a></p><?php endif; ?>
    <?php if ($p['dpo'] !== ''): ?><p class="mt-2"><strong>Datenschutzbeauftragte/r:</strong><br><?= nl2br($e($p['dpo'])) ?></p><?php endif; ?>
  </section>

  <section>
    <h2 class="section-title">2. Zweck dieses Dienstes</h2>
    <p class="mt-2"><?= $e($appName) ?> ist die zentrale Anmeldestelle (Single Sign-On) für Anwendungen von <?= $e($brand['org']) ?>. Mit einem Konto können Sie sich bei allen angeschlossenen Anwendungen anmelden.</p>
  </section>

  <section>
    <h2 class="section-title">3. Verarbeitete Daten, Zwecke und Rechtsgrundlagen</h2>
    <div class="table-wrap mt-2">
      <table class="w-full text-left align-top">
        <thead class="text-xs uppercase text-slate-500"><tr><th class="py-2 pr-3">Daten</th><th class="py-2 pr-3">Zweck</th><th class="py-2">Rechtsgrundlage</th></tr></thead>
        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
          <tr><td class="py-2 pr-3">Name, E-Mail-Adresse, Passwort (nur als Argon2id-Hash), Status, Rollen</td><td class="py-2 pr-3">Bereitstellung des Kontos und der Anmeldung</td><td class="py-2">Art. 6 Abs. 1 lit. b DSGVO (Nutzungsverhältnis)</td></tr>
          <tr><td class="py-2 pr-3">Zweiter Faktor: TOTP-Geheimnis (verschlüsselt), Recovery-Code-Hashes, Passkey-Öffentlicher-Schlüssel und Bezeichnung</td><td class="py-2 pr-3">Schutz Ihres Kontos</td><td class="py-2">Art. 6 Abs. 1 lit. b und f DSGVO</td></tr>
          <tr><td class="py-2 pr-3">Sitzungsdaten: IP-Adresse, Browser-Kennung, Zeitpunkte</td><td class="py-2 pr-3">Aufrechterhaltung der Anmeldung, Geräteübersicht, Missbrauchsschutz</td><td class="py-2">Art. 6 Abs. 1 lit. b und f DSGVO</td></tr>
          <tr><td class="py-2 pr-3">Sicherheitsprotokoll (Ereignis, Zeit, Konto, IP-Adresse, Browser-Kennung)</td><td class="py-2 pr-3">Erkennung und Aufklärung von Angriffen, Nachvollziehbarkeit von Administratoraktionen</td><td class="py-2">Art. 6 Abs. 1 lit. f DSGVO (IT-Sicherheit)</td></tr>
          <tr><td class="py-2 pr-3">Freigaben für Anwendungen (Zustimmungen, Rollen, Tokens)</td><td class="py-2 pr-3">Anmeldung an verbundenen Anwendungen; Übermittlung der freigegebenen Angaben (z. B. Name, E-Mail, Rollen) an die jeweilige Anwendung</td><td class="py-2">Art. 6 Abs. 1 lit. b DSGVO; Ihre Freigabe können Sie jederzeit widerrufen</td></tr>
          <tr><td class="py-2 pr-3">Nachweis der Kenntnisnahme dieser Erklärung bei der Registrierung (Zeitpunkt, Version)</td><td class="py-2 pr-3">Rechenschaft</td><td class="py-2">Art. 6 Abs. 1 lit. f DSGVO</td></tr>
          <tr><td class="py-2 pr-3">E-Mails (Bestätigung, Passwort-Reset, Sicherheitshinweise)</td><td class="py-2 pr-3">Kontoverwaltung und Sicherheit; keine Werbung</td><td class="py-2">Art. 6 Abs. 1 lit. b DSGVO</td></tr>
          <?php if ($providers): ?><tr><td class="py-2 pr-3">Bei Anmeldung über Dritte: Kennung des Anbieters, Name, E-Mail-Adresse</td><td class="py-2 pr-3">Anmeldung und Kontoverknüpfung über den von Ihnen gewählten Anbieter</td><td class="py-2">Art. 6 Abs. 1 lit. b DSGVO</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <p class="mt-2">Die Bereitstellung von Name, E-Mail-Adresse und Zugangsdaten ist für ein Konto erforderlich; ohne sie ist keine Nutzung möglich. Es findet keine automatisierte Entscheidungsfindung einschließlich Profiling im Sinne von Art. 22 DSGVO statt.</p>
  </section>

  <section>
    <h2 class="section-title">4. Empfänger</h2>
    <ul class="mt-2 list-inside list-disc space-y-1">
      <li>Angeschlossene Anwendungen erhalten nur die Angaben, die Sie bei der Freigabe bestätigen (z. B. pseudonyme Kennung, Name, E-Mail, Rollen).</li>
      <li>Hosting- und E-Mail-Dienstleister verarbeiten Daten in unserem Auftrag (Art. 28 DSGVO).</li>
      <?php if ($providers): ?><li>Wenn Sie sich über einen Drittanbieter anmelden (<?= $e(implode(', ', array_column($providers, 'label'))) ?>), erhält dieser beim Anmelden technische Daten (z. B. Ihre IP-Adresse) und wir erhalten die von Ihnen freigegebenen Profildaten. Einige Anbieter sitzen in Drittländern (z. B. USA); die Übermittlung erfolgt dort nach dem jeweiligen Angemessenheitsbeschluss oder Standardvertragsklauseln des Anbieters. Es gelten zusätzlich die Datenschutzbestimmungen des Anbieters. Die Nutzung ist freiwillig; Sie können sich stattdessen mit E-Mail und Passwort anmelden.</li><?php endif; ?>
      <li>Eine Weitergabe zu Werbezwecken findet nicht statt.</li>
    </ul>
  </section>

  <section>
    <h2 class="section-title">5. Speicherdauer</h2>
    <ul class="mt-2 list-inside list-disc space-y-1">
      <li>Kontodaten: bis zur Löschung des Kontos (jederzeit selbst möglich unter „Konto → Datenschutz“).</li>
      <li>Sitzungen: höchstens <?= (int) $p['sessionDays'] ?> Tag(e) bzw. bis zur Abmeldung; abgelaufene Sitzungen werden regelmäßig gelöscht.</li>
      <li>Sicherheitsprotokoll: <?= (int) $p['auditDays'] ?> Tage; IP-Adresse und Browser-Kennung darin werden bereits nach <?= (int) $p['ipDays'] ?> Tagen entfernt.</li>
      <li>Nach Löschung eines Kontos bleiben Sicherheitsprotokolle nur in anonymisierter Form (ohne Konto-, IP- und Browserbezug) bis zum Ablauf der Protokollfrist bestehen.</li>
      <li>Einmal-Links, abgelaufene Tokens und versendete E-Mails werden nach wenigen Tagen gelöscht.</li>
      <?php if ($p['inactiveDays'] >= 90): ?><li>Konten ohne Anmeldung seit <?= (int) $p['inactiveDays'] ?> Tagen werden nach vorheriger E-Mail-Warnung gelöscht.</li><?php endif; ?>
    </ul>
  </section>

  <section>
    <h2 class="section-title">6. Cookies</h2>
    <p class="mt-2">Wir setzen ausschließlich technisch erforderliche bzw. von Ihnen ausgelöste Cookies ein; eine Einwilligung ist dafür nicht nötig (§ 25 Abs. 2 TDDDG). Es gibt keine Tracking- oder Werbe-Cookies und keine externen Skripte, Schriften oder Analysedienste.</p>
    <ul class="mt-2 list-inside list-disc space-y-1">
      <li><code>__Host-vsid</code> bzw. Sitzungs-Cookie: hält Ihre Anmeldung aufrecht (bis Abmeldung/Ablauf).</li>
      <li><code>vtheme</code>: speichert Ihre Wahl „hell/dunkel“ (1 Jahr), nur wenn Sie den Umschalter benutzen.</li>
    </ul>
  </section>

  <section>
    <h2 class="section-title">7. Ihre Rechte</h2>
    <p class="mt-2">Sie haben das Recht auf Auskunft (Art. 15), Berichtigung (Art. 16), Löschung (Art. 17), Einschränkung der Verarbeitung (Art. 18), Datenübertragbarkeit (Art. 20) und Widerspruch gegen Verarbeitungen auf Grundlage berechtigter Interessen (Art. 21). Erteilte Freigaben können Sie jederzeit widerrufen (Art. 7 Abs. 3).</p>
    <ul class="mt-2 list-inside list-disc space-y-1">
      <li><strong>Selbstbedienung</strong> (angemeldet): unter <a class="link" href="/account/privacy">Konto → Datenschutz</a> können Sie Ihre Daten als JSON-Datei exportieren, Ihre E-Mail-Adresse ändern und Ihr Konto löschen; Ihren Namen ändern Sie unter „Konto“.</li>
      <?php if ($p['email'] !== ''): ?><li>Für alle weiteren Anliegen schreiben Sie an <a class="link" href="mailto:<?= $e($p['email']) ?>"><?= $e($p['email']) ?></a>. Wir antworten innerhalb eines Monats.</li><?php endif; ?>
    </ul>
    <p class="mt-2">Sie haben außerdem das Recht, sich bei einer Datenschutz-Aufsichtsbehörde zu beschweren<?php if ($p['authority'] !== ''): ?>, z. B.:<br><?= nl2br($e($p['authority'])) ?><?php else: ?> (z. B. bei der Aufsichtsbehörde Ihres Wohnorts oder am Sitz des Verantwortlichen)<?php endif; ?>.</p>
  </section>

  <section>
    <h2 class="section-title">8. Sicherheit</h2>
    <p class="mt-2">Passwörter werden nur als Hash gespeichert, die Übertragung erfolgt verschlüsselt (HTTPS), Anmeldungen sind gegen Brute-Force-Angriffe gedrosselt, Sitzungen und Tokens werden serverseitig verwaltet. Ein zweiter Faktor (Authenticator-App, Passkey) ist verfügbar.</p>
  </section>

  <?php if ($p['extra'] !== ''): ?>
  <section>
    <h2 class="section-title">9. Weitere Hinweise</h2>
    <p class="mt-2"><?= nl2br($e($p['extra'])) ?></p>
  </section>
  <?php endif; ?>
</article>

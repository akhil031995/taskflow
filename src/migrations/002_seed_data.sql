-- 002 seed data (optional sample content).
-- Uses INSERT IGNORE so it never errors on re-run and can be safely removed
-- before first boot if you want an empty database.

INSERT IGNORE INTO projects (id, name, description, color) VALUES
    (1, 'Website Redesign', 'Marketing site refresh and CMS migration.', '#6366f1'),
    (2, 'Mobile App v2', 'Ship the offline-first rewrite.', '#10b981');

INSERT IGNORE INTO tasks (id, project_id, title, description, status, priority, position) VALUES
    (1, 1, 'Audit current pages',       'Catalog every existing URL and its owner.', 'completed',   'medium', 0),
    (2, 1, 'Design new homepage',       'Hero, social proof, pricing teaser.',       'in_progress', 'high',   0),
    (3, 1, 'Migrate blog content',      'Move 120 posts into the new CMS.',          'pending',     'low',    0),
    (4, 1, 'Vendor contract review',    'Legal is still reviewing the CDN contract.','on_hold',     'urgent', 0),
    (5, 2, 'Set up local sync engine',  'CRDT-based offline store.',                 'in_progress', 'urgent', 0),
    (6, 2, 'Push notification service', 'APNs plus FCM abstraction.',                'pending',     'medium', 0);

INSERT IGNORE INTO notes (id, project_id, title, content, position) VALUES
    (1, 1, 'Kickoff Notes', '<h2>Kickoff</h2><p>Timeline: 6 weeks. Stakeholders aligned on scope.</p>', 0),
    (2, 1, 'Open Questions', '<ul><li>Do we keep the old URL structure?</li></ul>', 1),
    (3, 2, 'Architecture', '<p>Offline-first with a local write-ahead log.</p>', 0);

INSERT IGNORE INTO quick_links (id, title, url) VALUES
    (1, 'Tailwind Docs', 'https://tailwindcss.com/docs'),
    (2, 'Quill.js', 'https://quilljs.com/docs/quickstart');

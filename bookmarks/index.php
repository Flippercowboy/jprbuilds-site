<?php
require 'config.php';

// Fetch all categories
$categories = $pdo->query("SELECT * FROM categories")->fetchAll();

// Filter by category if selected
$where = '';
$params = [];
if (!empty($_GET['category'])) {
    $where = "WHERE b.category_id = ?";
    $params[] = $_GET['category'];
}

// Fetch bookmarks
$bookmarks = $pdo->prepare("
    SELECT b.*, c.name AS category_name 
    FROM bookmarks b
    LEFT JOIN categories c ON b.category_id = c.id
    $where
    ORDER BY b.created_at DESC
");
$bookmarks->execute($params);
$bookmarks = $bookmarks->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Bookmarks — JPR Builds</title>
  <link rel="stylesheet" href="/css/site.css" />
  <style>
    .hero {
      padding: 3rem 0 2rem;
    }

    .hero h1 {
      font-size: clamp(2rem, 5vw, 2.8rem);
      color: #fff;
      margin-bottom: 0.5rem;
    }

    /* ── ADD FORM ── */
    .add-form {
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 16px;
      padding: 1.5rem;
      margin-bottom: 2rem;
    }

    .add-form h2 {
      font-family: 'DM Sans', sans-serif;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: rgba(255,255,255,0.4);
      margin-bottom: 1rem;
    }

    .form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0.75rem;
    }

    .form-full { grid-column: 1 / -1; }

    .add-form label {
      display: block;
      font-size: 0.75rem;
      color: rgba(255,255,255,0.4);
      margin-bottom: 0.35rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .add-form input,
    .add-form select,
    .add-form textarea {
      width: 100%;
      padding: 0.6rem 0.85rem;
      background: rgba(255,255,255,0.07);
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: 8px;
      color: rgba(255,255,255,0.85);
      font-family: 'DM Sans', sans-serif;
      font-size: 0.9rem;
      outline: none;
      transition: border-color 0.2s;
      box-sizing: border-box;
    }

    .add-form input:focus,
    .add-form select:focus,
    .add-form textarea:focus {
      border-color: #a78bfa;
    }

    .add-form select option {
      background: #1a1833;
    }

    .add-form textarea { resize: vertical; min-height: 70px; }

    .form-actions {
      margin-top: 1rem;
      display: flex;
      justify-content: flex-end;
    }

    .btn-submit {
      padding: 0.6rem 1.5rem;
      background: linear-gradient(135deg, #667eea, #764ba2);
      color: #fff;
      border: none;
      border-radius: 10px;
      font-family: 'DM Sans', sans-serif;
      font-weight: 600;
      font-size: 0.9rem;
      cursor: pointer;
      transition: transform 0.2s, box-shadow 0.2s;
    }

    .btn-submit:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 15px rgba(102,126,234,0.4);
    }

    /* ── CATEGORY PILLS ── */
    .category-filter {
      display: flex;
      gap: 0.5rem;
      flex-wrap: wrap;
      margin-bottom: 1.5rem;
    }

    .category-filter a {
      padding: 0.3rem 0.85rem;
      border-radius: 20px;
      font-size: 0.8rem;
      text-decoration: none;
      color: rgba(255,255,255,0.5);
      border: 1px solid rgba(255,255,255,0.12);
      background: rgba(255,255,255,0.05);
      transition: all 0.2s;
    }

    .category-filter a:hover {
      color: #a78bfa;
      border-color: rgba(167,139,250,0.4);
    }

    .category-filter a.active {
      background: rgba(167,139,250,0.15);
      color: #a78bfa;
      border-color: rgba(167,139,250,0.4);
    }

    /* ── BOOKMARK CARDS ── */
    .bookmarks-list {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
    }

    .bookmark-card {
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 14px;
      padding: 1.1rem 1.25rem;
      transition: border-color 0.2s, transform 0.2s;
    }

    .bookmark-card:hover {
      border-color: rgba(167,139,250,0.35);
      transform: translateY(-2px);
    }

    .bookmark-card a.bookmark-title {
      font-size: 1rem;
      font-weight: 500;
      color: #e0e0e0;
      text-decoration: none;
      transition: color 0.2s;
    }

    .bookmark-card a.bookmark-title:hover {
      color: #a78bfa;
    }

    .bookmark-meta {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      margin-top: 0.35rem;
    }

    .bookmark-description {
      font-size: 0.85rem;
      color: rgba(255,255,255,0.4);
      margin-top: 0.4rem;
      line-height: 1.5;
    }

    .empty-state {
      text-align: center;
      padding: 3rem 0;
      color: rgba(255,255,255,0.3);
      font-size: 0.95rem;
    }

    @media (max-width: 600px) {
      .form-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

  <nav class="site-nav">
    <a href="/" class="back-link">← jprbuilds.com</a>
    <img src="/Logos/jprbuilds-logo-navbar.svg" alt="JPRBuilds" height="36" />
  </nav>

  <div class="site-container">

    <div class="hero">
      <h1>My <em class="accent">Bookmarks</em></h1>
      <p class="text-muted">Saved links, organised by category.</p>
    </div>

    <!-- ADD FORM -->
    <div class="add-form">
      <h2>Add a bookmark</h2>
      <form action="add.php" method="POST">
        <div class="form-grid">
          <div>
            <label>Title</label>
            <input type="text" name="title" required placeholder="Site name" />
          </div>
          <div>
            <label>URL</label>
            <input type="url" name="url" required placeholder="https://..." />
          </div>
          <div class="form-full">
            <label>Description</label>
            <textarea name="description" rows="2" placeholder="Optional note about this link"></textarea>
          </div>
          <div>
            <label>Category</label>
            <select name="category_id">
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-actions">
            <button type="submit" class="btn-submit">Add Bookmark</button>
          </div>
        </div>
      </form>
    </div>

    <!-- CATEGORY FILTER -->
    <div class="category-filter">
      <a href="index.php" <?= empty($_GET['category']) ? 'class="active"' : '' ?>>All</a>
      <?php foreach ($categories as $cat): ?>
        <a href="?category=<?= $cat['id'] ?>"
           <?= (!empty($_GET['category']) && $_GET['category'] == $cat['id']) ? 'class="active"' : '' ?>>
          <?= htmlspecialchars($cat['name']) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- BOOKMARKS -->
    <?php if (empty($bookmarks)): ?>
      <div class="empty-state">No bookmarks yet — add one above.</div>
    <?php else: ?>
      <div class="bookmarks-list">
        <?php foreach ($bookmarks as $b): ?>
          <div class="bookmark-card">
            <a href="<?= htmlspecialchars($b['url']) ?>" target="_blank" class="bookmark-title">
              <?= htmlspecialchars($b['title']) ?> ↗
            </a>
            <div class="bookmark-meta">
              <span class="tag"><?= htmlspecialchars($b['category_name']) ?></span>
              <span style="font-size:0.75rem;color:rgba(255,255,255,0.25)"><?= date('d M Y', strtotime($b['created_at'])) ?></span>
            </div>
            <?php if ($b['description']): ?>
              <div class="bookmark-description"><?= htmlspecialchars($b['description']) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>

  <footer class="site-footer">
    Jason Richards © 2026 &nbsp;·&nbsp; Daventry, UK
  </footer>

</body>
</html>

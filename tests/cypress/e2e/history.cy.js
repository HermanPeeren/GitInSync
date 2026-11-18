/**
 * The main path, on a site in the mode "Local repository": export, edit an article, see
 * the change committed, restore the old version.
 *
 * The site must have GitInSync installed and at least one article. Each step runs the
 * same jobs a user would, from the dashboard.
 */
describe('GitInSync history', () => {
  beforeEach(() => {
    cy.loginToAdmin();
  });

  /**
   * Runs a job from the dashboard and waits until run.js reports it done.
   */
  const runJob = (button) => {
    cy.visit('/administrator/index.php?option=com_gitinsync&view=dashboard');
    cy.get(button).click();
    cy.get('.gitinsync-run-status', { timeout: 120000 }).should(($status) => {
      expect($status.text()).to.match(/Done|Klaar/);
    });
  };

  it('shows the dashboard with the mode and branch', () => {
    cy.visit('/administrator/index.php?option=com_gitinsync&view=dashboard');
    cy.get('#gitinsync-mode').should('not.be.empty');
    cy.get('#gitinsync-branch').should('contain', 'main');
  });

  it('exports all content into a first commit', () => {
    runJob('#gitinsync-export');
    cy.visit('/administrator/index.php?option=com_gitinsync&view=history');
    cy.get('#gitinsync-history tbody tr').should('have.length.at.least', 1);
  });

  it('commits a saved article and restores its old version', () => {
    const marker = `Edited by Cypress ${Date.now()}`;

    cy.visit('/administrator/index.php?option=com_content&view=articles');
    cy.get('#articleList tbody tr a[href*="task=article.edit"]').first().click();

    let oldTitle = '';
    cy.get('#jform_title').invoke('val').then((value) => { oldTitle = String(value); });
    cy.get('#jform_title').clear().type(marker);
    cy.get('#save-group-children-save .button-save, button.button-save').first().click();
    cy.get('#system-message-container').should('contain', 'saved');

    runJob('#gitinsync-commit');

    cy.visit('/administrator/index.php?option=com_gitinsync&view=history');
    cy.get('#gitinsync-history').should('contain', marker);

    // The article's own history: restore the version before the edit.
    cy.visit('/administrator/index.php?option=com_content&view=articles');
    cy.contains('#articleList a', marker).click();
    cy.contains('button[role="tab"]', 'GitInSync').click();
    cy.get('#gitinsync-item-history-link').should('be.visible').invoke('attr', 'href').then((href) => {
      // Close the edit form first, so the article is not checked out while it is restored.
      cy.get('button.button-cancel').first().click();
      cy.visit(`/administrator/${href.substring(href.indexOf('index.php'))}`);
    });

    // The newest row is the current version; the first button restores the one before.
    cy.get('#gitinsync-item-history .gitinsync-restore').first().click();

    cy.get('#gitinsync-operation-status', { timeout: 120000 }).should('have.attr', 'data-status', 'done');

    cy.then(() => {
      cy.visit('/administrator/index.php?option=com_content&view=articles');
      cy.get('#articleList').should('contain', oldTitle).and('not.contain', marker);
    });
  });

  it('creates a branch and deletes it again', () => {
    const name = `cypress-${Date.now()}`;

    cy.visit('/administrator/index.php?option=com_gitinsync&view=branches');
    cy.get('#gitinsync-branch-name').type(name);
    cy.get('#gitinsync-new-branch button[type="submit"]').click();
    cy.get('#gitinsync-branches').should('contain', name);

    cy.on('window:confirm', () => true);
    cy.contains('#gitinsync-branches tr', name).find('.gitinsync-delete').click();
    cy.get('#gitinsync-branches').should('not.contain', name);
  });
});

# Selecting a PC when creating a ticket

- Open Tickets, Add, then select the club and the PC in the club dropdown.
- Choices show PC name, workstation number and zone where available. Names use
  natural numeric sorting. Only PCs in accessible clubs are returned to the page.
- Switching club clears the previous PC. The server validates club membership and
  rejects a mismatched equipment ID, including direct requests bypassing the UI.
- General club/network tickets may omit the PC. Existing tickets are not guessed
  or backfilled from free-text descriptions.
- The ticket stores the permanent `equipment_id`; its page links back to the PC.
- Equipment history includes open and closed tickets, 20 per page. Renaming a PC
  does not break the association. Pagination replaces the previous 50-row cutoff.
- No schema changes are required; the existing ticket/equipment foreign key is used.

Verification: 43 backend tests / 494 assertions passed in isolated VDS Docker,
including cross-club rejection, optional general tickets, history pagination and
retention of closed tickets after equipment rename.

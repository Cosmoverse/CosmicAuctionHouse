-- #!mysql
-- #{ auctionhouse
-- #  { init
-- #    { players
CREATE TABLE IF NOT EXISTS auction_house_players(
    uuid BINARY(16) NOT NULL PRIMARY KEY,
    gamertag VARCHAR(15) NOT NULL
);
-- #    }
-- #    { auction_house
CREATE TABLE IF NOT EXISTS auction_house(
    uuid BINARY(16) NOT NULL PRIMARY KEY,
    player BINARY(16) NOT NULL,
    price FLOAT NOT NULL,
    listing_time INT NOT NULL,
    expiry_time INT NOT NULL,
    item BLOB NOT NULL,
    FOREIGN KEY (player)
        REFERENCES auction_house_players(uuid)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);
-- #    }
-- #    { auction_house_logs
CREATE TABLE IF NOT EXISTS auction_house_logs(
    uuid BINARY(16) NOT NULL PRIMARY KEY,
    buyer BINARY(16) NOT NULL,
    seller BINARY(16) NOT NULL,
    listing_price FLOAT NOT NULL,
    purchase_price FLOAT NOT NULL,
    purchase_time INT NOT NULL,
    item BLOB NOT NULL,
    FOREIGN KEY (buyer)
        REFERENCES auction_house_players(uuid)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    FOREIGN KEY (seller)
        REFERENCES auction_house_players(uuid)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);
-- #    }
-- #  }
-- #  { load
SELECT ah.uuid, ahp.uuid AS player, ahp.gamertag, ah.price, ah.item, ah.listing_time, ah.expiry_time
FROM auction_house ah
INNER JOIN auction_house_players ahp
    ON ahp.uuid=ah.player
ORDER BY ah.listing_time;
-- #  }
-- #  { add
-- #    :uuid string
-- #    :player string
-- #    :price float
-- #    :listing_time int
-- #    :expiry_time int
-- #    :item string
INSERT INTO auction_house(uuid, player, price, listing_time, expiry_time, item) VALUES(:uuid, :player, :price, :listing_time, :expiry_time, :item);
-- #  }
-- #  { log
-- #    :uuid string
-- #    :buyer string
-- #    :seller string
-- #    :price float
-- #    :purchase_time int
-- #    :item string
INSERT INTO auction_house_logs(uuid, buyer, seller, price, purchase_time, item) VALUES(:uuid, :buyer, :seller, :price, :purchase_time, :item);
-- #  }
-- #  { remove
-- #    :uuid string
DELETE FROM auction_house WHERE uuid=:uuid;
-- #  }
-- #  { update_expiry
-- #    :uuid string
-- #    :time int
UPDATE auction_house SET expiry_time=:time WHERE uuid=:uuid;
-- #  }
-- #  { list
-- #    :offset int
-- #    :length int
SELECT uuid FROM auction_house WHERE expiry_time > unixepoch() ORDER BY listing_time DESC LIMIT :offset, :length;
-- #  }
-- #  { player
-- #    { init
-- #      :uuid string
-- #      :gamertag string
INSERT OR REPLACE INTO auction_house_players(uuid, gamertag) VALUES(:uuid, :gamertag);
-- #    }
-- #    { listings
-- #      :player string
SELECT uuid FROM auction_house WHERE player=:player AND expiry_time > unixepoch() ORDER BY listing_time DESC LIMIT 45;
-- #    }
-- #    { binned
-- #      :player string
SELECT uuid FROM auction_house WHERE player=:player AND expiry_time <= unixepoch() ORDER BY listing_time DESC LIMIT 45;
-- #    }
-- #    { stats
-- #      :player string
SELECT
    COUNT(CASE WHEN expiry_time <= unixepoch() THEN 1 END) AS binned,
    COUNT(CASE WHEN expiry_time > unixepoch() THEN 1 END) AS listings
FROM auction_house
WHERE player = :player;
-- #    }
-- #  }

-- #}
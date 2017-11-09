<?php

namespace InstagramScraper;

use InstagramScraper\Exception\InstagramAuthException;
use InstagramScraper\Exception\InstagramException;
use InstagramScraper\Exception\InstagramNotFoundException;
use InstagramScraper\Model\Account;
use InstagramScraper\Model\Comment;
use InstagramScraper\Model\Location;
use InstagramScraper\Model\Media;
use InstagramScraper\Model\Tag;
use phpFastCache\CacheManager;
use Unirest\Request;

class Instagram
{
    const HTTP_NOT_FOUND = 404;
    const HTTP_OK = 200;
    const MAX_COMMENTS_PER_REQUEST = 300;

    private static $instanceCache;
    private $sessionUsername;
    private $sessionPassword;
    private $userSession;

    /**
     * @param string $username
     * @param string $password
     * @param null $sessionFolder
     *
     * @return Instagram
     */
    public static function withCredentials($username, $password, $sessionFolder = null)
    {
        if (is_null($sessionFolder)) {
            $sessionFolder = __DIR__ . DIRECTORY_SEPARATOR . 'sessions' . DIRECTORY_SEPARATOR;
        }
        if (is_string($sessionFolder)) {
            CacheManager::setDefaultConfig([
                'path' => $sessionFolder,
            ]);
            static::$instanceCache = CacheManager::getInstance('files');
        } else {
            static::$instanceCache = $sessionFolder;
        }
        $instance = new self();
        $instance->sessionUsername = $username;
        $instance->sessionPassword = $password;
        return $instance;
    }

    /**
     * @param string $tag
     *
     * @return array
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public static function searchTagsByTagName($tag)
    {
        // TODO: Add tests and auth
        $response = Request::get(Endpoints::getGeneralSearchJsonLink($tag));
        // use a raw constant in the code is not a good idea!!
        //if ($response->code === 404) {
        if (static::HTTP_NOT_FOUND === $response->code) {
            throw new InstagramNotFoundException('Account with given username does not exist.');
        }
        // use a raw constant in the code is not a good idea!!
        //if ($response->code !== 200) {
        if (static::HTTP_OK !== $response->code) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }

        $jsonResponse = json_decode($response->raw_body, true);
        if (!isset($jsonResponse['status']) || $jsonResponse['status'] !== 'ok') {
            throw new InstagramException('Response code is not equal 200. Something went wrong. Please report issue.');
        }

        if (!isset($jsonResponse['hashtags']) || empty($jsonResponse['hashtags'])) {
            return [];
        }
        $hashtags = [];
        foreach ($jsonResponse['hashtags'] as $jsonHashtag) {
            $hashtags[] = Tag::create($jsonHashtag['hashtag']);
        }
        return $hashtags;
    }

    /**
     * @param \stdClass|string $rawError
     *
     * @return string
     */
    private static function getErrorBody($rawError)
    {
        if (is_string($rawError)) {
            return $rawError;
        }
        if (is_object($rawError)) {
            $str = '';
            foreach ($rawError as $key => $value) {
                $str .= ' ' . $key . ' => ' . $value . ';';
            }
            return $str;
        } else {
            return 'Unknown body format';
        }

    }

    /**
     * @param string $username
     *
     * @return Account[]
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function searchAccountsByUsername($username)
    {
        $response = Request::get(Endpoints::getGeneralSearchJsonLink($username), $this->generateHeaders($this->userSession));
        if (static::HTTP_NOT_FOUND === $response->code) {
            throw new InstagramNotFoundException('Account with given username does not exist.');
        }
        if (static::HTTP_OK !== $response->code) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }

        $jsonResponse = json_decode($response->raw_body, true);
        if (!isset($jsonResponse['status']) || $jsonResponse['status'] !== 'ok') {
            throw new InstagramException('Response code is not equal 200. Something went wrong. Please report issue.');
        }
        if (!isset($jsonResponse['users']) || empty($jsonResponse['users'])) {
            return [];
        }

        $accounts = [];
        foreach ($jsonResponse['users'] as $jsonAccount) {
            $accounts[] = Account::create($jsonAccount['user']);
        }
        return $accounts;
    }

    /**
     * @param $session
     *
     * @return array
     */
    private function generateHeaders($session)
    {
        $headers = [];
        if ($session) {
            $cookies = '';
            foreach ($session as $key => $value) {
                $cookies .= "$key=$value; ";
            }
            $headers = [
                'cookie' => $cookies,
                'referer' => Endpoints::BASE_URL . '/',
                'x-csrftoken' => $session['csrftoken'],
            ];
        }
        return $headers;
    }

    /**
     * @param string $username
     * @param int $count
     * @param string $maxId
     *
     * @return Media[]
     * @throws InstagramException
     */
    public function getMedias($username, $count = 20, $maxId = '')
    {
        $index = 0;
        $medias = [];
        $isMoreAvailable = true;
        while ($index < $count && $isMoreAvailable) {
            $response = Request::get(Endpoints::getAccountMediasJsonLink($username, $maxId), $this->generateHeaders($this->userSession));
            if (static::HTTP_OK !== $response->code) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
            }

            $arr = json_decode($response->raw_body, true);
            if (!is_array($arr)) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
            }
            $nodes = $arr['user']['media']['nodes'];
            // fix - count takes longer/has more overhead
            if (!isset($nodes) || empty($nodes)) {
                return [];
            }
            foreach ($nodes as $mediaArray) {
                if ($index === $count) {
                    return $medias;
                }
                $medias[] = Media::create($mediaArray);
                $index++;
            }
            if (empty($nodes) || !isset($nodes)) {
                return $medias;
            }
            $maxId = $nodes[count($nodes) - 1]['id'];
            $isMoreAvailable = $arr['user']['media']['page_info']['has_next_page'];
        }
        return $medias;
    }

    /**
     * @param $mediaId
     *
     * @return Media
     */
    public function getMediaById($mediaId)
    {
        $mediaLink = Media::getLinkFromId($mediaId);
        return $this->getMediaByUrl($mediaLink);
    }

    /**
     * @param string $mediaUrl
     *
     * @return Media
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getMediaByUrl($mediaUrl)
    {
        if (filter_var($mediaUrl, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('Malformed media url');
        }
        $response = Request::get(rtrim($mediaUrl, '/') . '/?__a=1', $this->generateHeaders($this->userSession));
        // use a raw constant in the code is not a good idea!!
        //if ($response->code === 404) {
        if (static::HTTP_NOT_FOUND === $response->code) {
            throw new InstagramNotFoundException('Media with given code does not exist or account is private.');
        }
        // use a raw constant in the code is not a good idea!!
        //if ($response->code !== 200) {
        if (static::HTTP_OK !== $response->code) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }
        $mediaArray = json_decode($response->raw_body, true);
        if (!isset($mediaArray['graphql']['shortcode_media'])) {
            throw new InstagramException('Media with this code does not exist');
        }
        return Media::create($mediaArray['graphql']['shortcode_media']);
    }

    /**
     * @param string $mediaCode (for example BHaRdodBouH)
     *
     * @return Media
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */

    public function getMediaByCode($mediaCode)
    {
        $url = Endpoints::getMediaPageLink($mediaCode);
        return $this->getMediaByUrl($url);

    }

    /**
     * @param string $username
     * @param string $maxId
     *
     * @return array
     * @throws InstagramException
     */
    public function getPaginateMedias($username, $maxId = '')
    {
        $hasNextPage = true;
        $medias = [];

        $toReturn = [
            'medias' => $medias,
            'maxId' => $maxId,
            'hasNextPage' => $hasNextPage,
        ];

        $response = Request::get(Endpoints::getAccountMediasJsonLink($username, $maxId),
            $this->generateHeaders($this->userSession));

        // use a raw constant in the code is not a good idea!!
        //if ($response->code !== 200) {
        if (static::HTTP_OK !== $response->code) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }

        $arr = json_decode($response->raw_body, true);

        if (!is_array($arr)) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }

        //if (count($arr['items']) === 0) {
        // I generally use empty. Im not sure why people would use count really - If the array is large then count takes longer/has more overhead.
        // If you simply need to know whether or not the array is empty then use empty.
        if (empty($arr['items'])) {
            return $toReturn;
        }

        foreach ($arr['items'] as $mediaArray) {
            $medias[] = Media::create($mediaArray);
        }

        $maxId = $arr['items'][count($arr['items']) - 1]['id'];
        $hasNextPage = $arr['more_available'];

        $toReturn = [
            'medias' => $medias,
            'maxId' => $maxId,
            'hasNextPage' => $hasNextPage,
        ];

        return $toReturn;
    }

    /**
     * @param      $mediaId
     * @param int $count
     * @param null $maxId
     *
     * @return Comment[]
     */
    public function getMediaCommentsById($mediaId, $count = 10, $maxId = null)
    {
        $code = Media::getCodeFromId($mediaId);
        return static::getMediaCommentsByCode($code, $count, $maxId);
    }

    /**
     * @param      $code
     * @param int $count
     * @param null $maxId
     *
     * @return Comment[]
     * @throws InstagramException
     */
    public function getMediaCommentsByCode($code, $count = 10, $maxId = null)
    {
        $remain = $count;
        $comments = [];
        $index = 0;
        $hasPrevious = true;
        while ($hasPrevious && $index < $count) {
            if ($remain > static::MAX_COMMENTS_PER_REQUEST) {
                $numberOfCommentsToRetreive = static::MAX_COMMENTS_PER_REQUEST;
                $remain -= static::MAX_COMMENTS_PER_REQUEST;
                $index += static::MAX_COMMENTS_PER_REQUEST;
            } else {
                $numberOfCommentsToRetreive = $remain;
                $index += $remain;
                $remain = 0;
            }
            if (!isset($maxId)) {
                $maxId = '';

            }
            $commentsUrl = Endpoints::getCommentsBeforeCommentIdByCode($code, $numberOfCommentsToRetreive, $maxId);
            $response = Request::get($commentsUrl, $this->generateHeaders($this->userSession));
            // use a raw constant in the code is not a good idea!!
            //if ($response->code !== 200) {
            if (static::HTTP_OK !== $response->code) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
            }
            $cookies = static::parseCookies($response->headers['Set-Cookie']);
            $this->userSession['csrftoken'] = $cookies['csrftoken'];
            $jsonResponse = json_decode($response->raw_body, true);
            $nodes = $jsonResponse['data']['shortcode_media']['edge_media_to_comment']['edges'];
            foreach ($nodes as $commentArray) {
                $comments[] = Comment::create($commentArray['node']);
            }
            $hasPrevious = $jsonResponse['data']['shortcode_media']['edge_media_to_comment']['page_info']['has_next_page'];
            $numberOfComments = $jsonResponse['data']['shortcode_media']['edge_media_to_comment']['count'];
            if ($count > $numberOfComments) {
                $count = $numberOfComments;
            }
            if (sizeof($nodes) == 0) {
                return $comments;
            }
            $maxId = $nodes[sizeof($nodes) - 1]['node']['id'];
        }
        return $comments;
    }

    /**
     * @param string $rawCookies
     *
     * @return array
     */
    private static function parseCookies($rawCookies)
    {
        if (!is_array($rawCookies)) {
            $rawCookies = [$rawCookies];
        }

        $cookies = [];
        foreach ($rawCookies as $c) {
            $c = explode(';', $c)[0];
            $parts = explode('=', $c);
            if (sizeof($parts) >= 2 && !is_null($parts[1])) {
                $cookies[$parts[0]] = $parts[1];
            }
        }
        return $cookies;
    }

    /**
     * @param string $id
     *
     * @return Account
     * @throws InstagramException
     * @throws \InvalidArgumentException
     */
    public function getAccountById($id)
    {
        // Use the follow page to get the account. The follow url will redirect to the home page for the user,
        // which has the username embedded in the url.

        if (!is_numeric($id)) {
            throw new \InvalidArgumentException('User id must be integer or integer wrapped in string');
        }

        $url = Endpoints::getFollowUrl($id);

        // Cut a request by disabling redirects.
        Request::curlOpt(CURLOPT_FOLLOWLOCATION, FALSE);
        $response = Request::get($url, $this->generateHeaders($this->userSession));
        Request::curlOpt(CURLOPT_FOLLOWLOCATION, TRUE);

        if ($response->code === 400) {
            throw new InstagramException('Account with this id does not exist.');
        }

        if ($response->code !== 302) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->raw_body) . ' Something went wrong. Please report issue.');
        }

        $cookies = static::parseCookies($response->headers['Set-Cookie']);
        $this->userSession['csrftoken'] = $cookies['csrftoken'];

        // Get the username from the response url.
        $responseUrl = $response->headers['Location'];
        $urlParts = explode('/', rtrim($responseUrl, '/'));
        $username = end($urlParts);

        return $this->getAccount($username);
    }

    /**
     * @param string $username
     *
     * @return Account
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getAccount($username)
    {
        $response = Request::get(Endpoints::getAccountJsonLink($username), $this->generateHeaders($this->userSession));
        if (static::HTTP_NOT_FOUND === $response->code) {
            throw new InstagramNotFoundException('Account with given username does not exist.');
        }
        if (static::HTTP_OK !== $response->code) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }

        $userArray = json_decode($response->raw_body, true);
        if (!isset($userArray['user'])) {
            throw new InstagramException('Account with this username does not exist');
        }
        return Account::create($userArray['user']);
    }

    /**
     * @param string $tag
     * @param int $count
     * @param string $maxId
     * @param string $minTimestamp
     *
     * @return Media[]
     * @throws InstagramException
     */
    public function getMediasByTag($tag, $count = 12, $maxId = '', $minTimestamp = null)
    {
        $index = 0;
        $medias = [];
        $mediaIds = [];
        $hasNextPage = true;
        while ($index < $count && $hasNextPage) {
            $response = Request::get(Endpoints::getMediasJsonByTagLink($tag, $maxId),
                $this->generateHeaders($this->userSession));
            if ($response->code !== 200) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
            }
            $cookies = static::parseCookies($response->headers['Set-Cookie']);
            $this->userSession['csrftoken'] = $cookies['csrftoken'];
            $arr = json_decode($response->raw_body, true);
            if (!is_array($arr)) {
                throw new InstagramException('Response decoding failed. Returned data corrupted or this library outdated. Please report issue');
            }
            if (empty($arr['tag']['media']['count'])) {
                return [];
            }
            $nodes = $arr['tag']['media']['nodes'];
            foreach ($nodes as $mediaArray) {
                if ($index === $count) {
                    return $medias;
                }
                $media = Media::create($mediaArray);
                if (in_array($media->getId(), $mediaIds)) {
                    return $medias;
                }
                if (isset($minTimestamp) && $media->getCreatedTime() < $minTimestamp) {
                    return $medias;
                }
                $mediaIds[] = $media->getId();
                $medias[] = $media;
                $index++;
            }
            if (empty($nodes)) {
                return $medias;
            }
            $maxId = $arr['tag']['media']['page_info']['end_cursor'];
            $hasNextPage = $arr['tag']['media']['page_info']['has_next_page'];
        }
        return $medias;

    }

    /**
     * @param string $tag
     * @param string $maxId
     *
     * @return array
     * @throws InstagramException
     */
    public function getPaginateMediasByTag($tag, $maxId = '')
    {
        $hasNextPage = true;
        $medias = [];

        $toReturn = [
            'medias' => $medias,
            'maxId' => $maxId,
            'hasNextPage' => $hasNextPage,
        ];

        $response = Request::get(Endpoints::getMediasJsonByTagLink($tag, $maxId),
            $this->generateHeaders($this->userSession));

        if ($response->code !== 200) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }

        $cookies = static::parseCookies($response->headers['Set-Cookie']);
        $this->userSession['csrftoken'] = $cookies['csrftoken'];

        $arr = json_decode($response->raw_body, true);

        if (!is_array($arr)) {
            throw new InstagramException('Response decoding failed. Returned data corrupted or this library outdated. Please report issue');
        }

        if (empty($arr['tag']['media']['count'])) {
            return $toReturn;
        }

        $nodes = $arr['tag']['media']['nodes'];

        if (empty($nodes)) {
            return $toReturn;
        }

        foreach ($nodes as $mediaArray) {
            $medias[] = Media::create($mediaArray);
        }

        $maxId = $arr['tag']['media']['page_info']['end_cursor'];
        $hasNextPage = $arr['tag']['media']['page_info']['has_next_page'];
        $count = $arr['tag']['media']['count'];

        $toReturn = [
            'medias' => $medias,
            'count' => $count,
            'maxId' => $maxId,
            'hasNextPage' => $hasNextPage,
        ];

        return $toReturn;
    }

    /**
     * @param $tagName
     *
     * @return Media[]
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getCurrentTopMediasByTagName($tagName)
    {
        $response = Request::get(Endpoints::getMediasJsonByTagLink($tagName, ''),
            $this->generateHeaders($this->userSession));
        if ($response->code === 404) {
            throw new InstagramNotFoundException('Account with given username does not exist.');
        }
        if ($response->code !== 200) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }
        $cookies = static::parseCookies($response->headers['Set-Cookie']);
        $this->userSession['csrftoken'] = $cookies['csrftoken'];
        $jsonResponse = json_decode($response->raw_body, true);
        $medias = [];
        foreach ($jsonResponse['tag']['top_posts']['nodes'] as $mediaArray) {
            $medias[] = Media::create($mediaArray);
        }
        return $medias;
    }

    /**
     * @param $facebookLocationId
     *
     * @return Media[]
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getCurrentTopMediasByLocationId($facebookLocationId)
    {
        $response = Request::get(Endpoints::getMediasJsonByLocationIdLink($facebookLocationId),
            $this->generateHeaders($this->userSession));
        if ($response->code === 404) {
            throw new InstagramNotFoundException('Location with this id doesn\'t exist');
        }
        if ($response->code !== 200) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }
        $cookies = static::parseCookies($response->headers['Set-Cookie']);
        $this->userSession['csrftoken'] = $cookies['csrftoken'];
        $jsonResponse = json_decode($response->raw_body, true);
        $nodes = $jsonResponse['location']['top_posts']['nodes'];
        $medias = [];
        foreach ($nodes as $mediaArray) {
            $medias[] = Media::create($mediaArray);
        }
        return $medias;
    }

    /**
     * @param string $facebookLocationId
     * @param int $quantity
     * @param string $offset
     *
     * @return Media[]
     * @throws InstagramException
     */
    public function getMediasByLocationId($facebookLocationId, $quantity = 12, $offset = '')
    {
        $index = 0;
        $medias = [];
        $hasNext = true;
        while ($index < $quantity && $hasNext) {
            $response = Request::get(Endpoints::getMediasJsonByLocationIdLink($facebookLocationId, $offset),
                $this->generateHeaders($this->userSession));
            if ($response->code !== 200) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
            }
            $cookies = static::parseCookies($response->headers['Set-Cookie']);
            $this->userSession['csrftoken'] = $cookies['csrftoken'];
            $arr = json_decode($response->raw_body, true);
            $nodes = $arr['location']['media']['nodes'];
            foreach ($nodes as $mediaArray) {
                if ($index === $quantity) {
                    return $medias;
                }
                $medias[] = Media::create($mediaArray);
                $index++;
            }
            if (empty($nodes)) {
                return $medias;
            }
            $hasNext = $arr['location']['media']['page_info']['has_next_page'];
            $offset = $arr['location']['media']['page_info']['end_cursor'];
        }
        return $medias;
    }

    /**
     * @param string $facebookLocationId
     *
     * @return Location
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getLocationById($facebookLocationId)
    {
        $response = Request::get(Endpoints::getMediasJsonByLocationIdLink($facebookLocationId),
            $this->generateHeaders($this->userSession));
        if ($response->code === 404) {
            throw new InstagramNotFoundException('Location with this id doesn\'t exist');
        }
        if ($response->code !== 200) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }
        $cookies = static::parseCookies($response->headers['Set-Cookie']);
        $this->userSession['csrftoken'] = $cookies['csrftoken'];
        $jsonResponse = json_decode($response->raw_body, true);
        return Location::create($jsonResponse['location']);
    }

    /**
     * @param string $accountId Account id of the profile to query
     * @param int $count Total followers to retrieve
     * @param int $pageSize Internal page size for pagination
     * @param bool $delayed Use random delay between requests to mimic browser behaviour
     *
     * @return array
     * @throws InstagramException
     */
    public function getFollowers($accountId, $count = 20, $pageSize = 20, $delayed = true)
    {
        if ($delayed) {
            set_time_limit(1800); // 30 mins
        }

        $index = 0;
        $accounts = [];
        $endCursor = '';

        if ($count < $pageSize) {
            throw new InstagramException('Count must be greater than or equal to page size.');
        }

        while (true) {
            $response = Request::get(Endpoints::getFollowersJsonLink($accountId, $pageSize, $endCursor),
                $this->generateHeaders($this->userSession));
            if ($response->code !== 200) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
            }

            $jsonResponse = json_decode($response->raw_body, true);

            if ($jsonResponse['data']['user']['edge_followed_by']['count'] === 0) {
                return $accounts;
            }

            $edgesArray = $jsonResponse['data']['user']['edge_followed_by']['edges'];
            if (count($edgesArray) === 0) {
                throw new InstagramException('Failed to get followers of account id ' . $accountId . '. The account is private.');
            }

            foreach ($edgesArray as $edge) {
                $accounts[] = $edge['node'];
                $index++;
                if ($index >= $count) {
                    break 2;
                }
            }

            $pageInfo = $jsonResponse['data']['user']['edge_followed_by']['page_info'];
            if ($pageInfo['has_next_page']) {
                $endCursor = $pageInfo['end_cursor'];
            } else {
                break;
            }

            if ($delayed) {
                // Random wait between 1 and 3 sec to mimic browser
                $microsec = rand(1000000, 3000000);
                usleep($microsec);
            }
        }
        return $accounts;
    }

    /**
     * @param bool $force
     *
     * @throws InstagramAuthException
     * @throws InstagramException
     *
     * @return array
     */
    public function login($force = false)
    {
        if ($this->sessionUsername == null || $this->sessionPassword == null) {
            throw new InstagramAuthException("User credentials not provided");
        }

        $cachedString = static::$instanceCache->getItem($this->sessionUsername);
        $session = $cachedString->get();
        if ($force || !$this->isLoggedIn($session)) {
            $response = Request::get(Endpoints::BASE_URL);
            if ($response->code !== 200) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
            }
            $cookies = static::parseCookies($response->headers['Set-Cookie']);
            $mid = $cookies['mid'];
            $csrfToken = $cookies['csrftoken'];
            $headers = ['cookie' => "csrftoken=$csrfToken; mid=$mid;",
                'referer' => Endpoints::BASE_URL . '/',
                'x-csrftoken' => $csrfToken,
            ];
            $response = Request::post(Endpoints::LOGIN_URL, $headers,
                ['username' => $this->sessionUsername, 'password' => $this->sessionPassword]);

            if ($response->code !== 200) {
                if ((is_string($response->code) || is_numeric($response->code)) && is_string($response->body)) {
                    throw new InstagramAuthException('Response code is ' . $response->code . '. Body: ' . $response->body . ' Something went wrong. Please report issue.');
                } else {
                    throw new InstagramAuthException('Something went wrong. Please report issue.');
                }
            }

            if (is_object($response->body)) {
                if (!$response->body->authenticated) {
                    throw new InstagramAuthException('User credentials are wrong.');
                }
            }

            $cookies = static::parseCookies($response->headers['Set-Cookie']);
            $cookies['mid'] = $mid;
            $cachedString->set($cookies);
            static::$instanceCache->save($cachedString);
            $this->userSession = $cookies;
        } else {
            $this->userSession = $session;
        }

        return $this->generateHeaders($this->userSession);
    }

    /**
     * @param $session
     *
     * @return bool
     */
    public function isLoggedIn($session)
    {
        if (is_null($session) || !isset($session['sessionid'])) {
            return false;
        }
        $sessionId = $session['sessionid'];
        $csrfToken = $session['csrftoken'];
        $headers = ['cookie' => "csrftoken=$csrfToken; sessionid=$sessionId;",
            'referer' => Endpoints::BASE_URL . '/',
            'x-csrftoken' => $csrfToken,
        ];
        $response = Request::get(Endpoints::BASE_URL, $headers);
        if ($response->code !== 200) {
            return false;
        }
        $cookies = static::parseCookies($response->headers['Set-Cookie']);
        if (!isset($cookies['ds_user_id'])) {
            return false;
        }
        return true;
    }

    /**
     *
     */
    public function saveSession()
    {
        $cachedString = static::$instanceCache->getItem($this->sessionUsername);
        $cachedString->set($this->userSession);
    }

}

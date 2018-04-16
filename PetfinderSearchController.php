<?php
/**
 * @author David Ocumarez <documarezc@gmail.com>
 */

namespace TresUp\SimplePuppy\Controllers\Frontend\DogSearch;

use Log;
use Exception;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\View\View;
use SalernoLabs\Petfinder\Exceptions\InvalidLocation;
use SalernoLabs\Petfinder\Exceptions\RecordDoesNotExist;
use Session;
use TresUp\SimplePuppy\Repositories\Classes\Dog\Petfinder;

/**
 * Class PetfinderSearchController
 * @package TresUp\SimplePuppy\Controllers\Frontend\DogSearch
 */
class PetfinderSearchController extends Controller
{
    /**
     * Load Petfinder Search Page.
     *
     * @param Request $request
     * @return View
     */
    public function showPetfinderSearch(Request $request)
    {
        $petfinder = new Petfinder;

        $ages = $petfinder->getAges();

        $genders = $petfinder->getGenders();

        $sizes = $petfinder->getSizes();

        $apiRequest = $petfinder->getApiRequest();

        $lastOffset = $petfinder->getOffset();

        // Set the default values for the criteria and pass $dogs as an empty array.
        if( empty($searchCriteria = $request->all()) ) {
            $searchCriteria = [
                'location' => '',
                'size' => '',
                'breed' => '',
                'age' => '',
                'gender' => ''
            ];
            $dogs = [];
        // If search criteria received, then load dogs and pass to the view.
        } else {

            try {
                $dogs = json_decode($petfinder->findDogs($request))->data->pets;
                $lastOffset = $petfinder->getOffset();

            } catch (RecordDoesNotExist $e) {
                $message = 'No Dogs found for the criteria provided. Please make some changes and try again.';

                return view('simplepuppy::frontend.search.search')->with(compact('dogs', 'breeds', 'ages', 'genders', 'sizes', 'searchCriteria', 'message', 'apiRequest', 'lastOffset'));

            } catch (InvalidLocation $e) {
                Log::alert("Petfinder search page: Invalid zip code exception");
                $message = 'The zip code provided is invalid. Please change it and try again.';

                return view('simplepuppy::frontend.search.search')->with(compact('dogs', 'breeds', 'ages', 'genders', 'sizes', 'searchCriteria', 'message', 'apiRequest', 'lastOffset'));

            } catch (Exception $e) {
                Log::alert("Petfinder search page: " . $e->getMessage());
                $message = 'The criteria provided is invalid. Please make some changes and try again.';

                return view('simplepuppy::frontend.search.search')->with(compact('dogs', 'breeds', 'ages', 'genders', 'sizes', 'searchCriteria', 'message', 'apiRequest', 'lastOffset'));
            }


        }

        $petfinder->setSearchCriteria($searchCriteria);

        $breeds = $petfinder->getCacheBreedList();

        $message = '';

        return view('simplepuppy::frontend.search.search')->with(compact('dogs', 'breeds', 'ages', 'genders', 'sizes', 'searchCriteria', 'message', 'apiRequest', 'lastOffset'));
    }

    /**
     * Search for dogs by the given search criteria and return the request result.
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function findDogs(Request $request)
    {
        try {
            $petfinder = new Petfinder;

            return $petfinder->findDogs($request);

        } catch (RecordDoesNotExist $e) {
            $response['fail'] = true;
            $response['message'] = 'No Dogs found for the criteria provided. Please make some changes and try again.';

            return json_encode($response);

        } catch (InvalidLocation $e) {
            Log::alert("Petfinder search page: Invalid zip code exception");
            $response['fail'] = true;
            $response['message'] = 'The zip code provided is invalid. Please change it and try again.';

            return json_encode($response);

        } catch (Exception $e) {
            Log::alert("Petfinder search page: " . $e->getMessage());
            $response['fail'] = true;
            $response['message'] = 'The criteria provided is invalid. Please make some changes and try again.';

            return json_encode($response);
        }
    }

    /**
     * Gets a Petfinder Dog by Id.
     *
     * @param Request $request
     *
     * @return mixed
     *
     */
    public function getDog(Request $request)
    {
        try {
            $id = $request->id;

            $petfinder = new Petfinder;
            $petfinder->getDogData($id);

            // Create the response structure to prevent array index fail in the view.
            $response['dog']['id'] = null;
            $response['dog']['shelterDogId'] = null;
            $response['dog']['name'] = null;
            $response['dog']['photos'] = [];
            $response['dog']['breed'] = null;
            $response['dog']['breed_link'] = null;
            $response['dog']['age'] = null;
            $response['dog']['sex'] = null;
            $response['dog']['size'] = null;
            $response['dog']['description'] = null;
            $response['dog']['contact'] = null;
            $response['dog']['location'] = null;
            $response['dog']['shelter_id'] = null;
            $response['fail'] = false;
            $response['message'] = null;
            $response['source'] = null;
            $response['shelter']['dogs'] = null;
            $response['shelter']['details'] = null;
            $response['general']['recentlyViewed'] = null;
            $response['general']['genders'] = null;
            $response['general']['sizes'] = null;

            // Adds dog information to the response.
            $response['dog']['id'] = $id;
            $response = $this->_prepareResponseFromDog($response, $petfinder);

            // Adds Shelter dogs and details to the response.
            $response = $this->_addShelterDogsToResponse($response, $petfinder);
            $response = $this->_addShelterDetailsToResponse($response, $petfinder);

            // Adds general information to the response.
            $response['general']['recentlyViewed'] = $this->_getRecentlyViewedDogs($id);
            $response['general']['genders'] = $petfinder->getGenders();
            $response['general']['sizes'] = $petfinder->getSizes();

            // Adds the viewed Dog Id into the Session.
            $this->_addDogToRecentlyViewed($id);

            return view('simplepuppy::frontend.search.dog')->with(compact('response'));

        } catch (RecordDoesNotExist $e) {
            Log::alert("Petfinder dog details page: Invalid dog id $id provided.");
            $response['fail'] = true;
            $response['message'] = 'This Dog was just adopted or is not longer available. Please select another Dog to view the details.';
            return view('simplepuppy::frontend.search.dog')->with(compact('response'));
        } catch (Exception $e) {
            Log::alert("Petfinder dog details page: Unidentified exception for dog id $id.");
            $response['fail'] = true;
            $response['message'] = 'This Dog was just adopted or is not longer available. Please select another Dog to view the details.';
            return view('simplepuppy::frontend.search.dog')->with(compact('response'));
        }
    }

    /**
     * Adds a Dog Id to the recentlyViewedDogs array in Session.
     *
     * @param string $dogId
     *
     * @return void
     */
    private function _addDogToRecentlyViewed(string $dogId)
    {
        $viewedDogs = Session::get('recentlyViewedDogs');
        if( !$viewedDogs || !in_array($dogId, $viewedDogs) ) {
            Session::push('recentlyViewedDogs', $dogId);
        }
    }

    /**
     * Gets all recently viewed Dogs within the active Session.
     *
     * @param string $currentDogId The current Dog Id to be excluded from the response.
     * @return array
     */
    private function _getRecentlyViewedDogs(string $currentDogId)
    {
        $viewedDogsResponse = [];
        $viewedDogs = Session::get('recentlyViewedDogs');

        if($viewedDogs) {
            foreach ($viewedDogs as $dogId)
            {
                if($dogId != $currentDogId) {
                    $petfinder = new Petfinder;
                    $petfinder->getDogData($dogId);

                    $response = [];
                    $response['dog']['id'] = $dogId;
                    $response = $this->_prepareResponseFromDog($response, $petfinder);

                    $viewedDogsResponse[] = $response;
                }

            }
        }
        return $viewedDogsResponse;
    }

    /**
     * Helper function to prepare getDog response from a Petfinder dog.
     *
     * @param array $response
     * @param Petfinder $petfinder
     *
     * @return array $response
     */
    private function _prepareResponseFromDog(array $response, Petfinder $petfinder)
    {
        $response['dog']['shelterDogId'] = $petfinder->getShelterDogId();
        $response['dog']['name'] = $petfinder->getName();
        $response['dog']['photos'] = $petfinder->getPhotos();
        $response['dog']['breed'] = $petfinder->getBreed();
        $response['dog']['breed_link'] = str_replace(' ', '-', strtolower($response['dog']['breed']));
        $response['dog']['age'] = $petfinder->getAge();
        $response['dog']['sex'] = $petfinder->getSex();
        $response['dog']['size'] = $petfinder->getSize();
        $response['dog']['description'] = $petfinder->getDescription();
        $response['dog']['contact'] = $petfinder->getContact();
        $response['dog']['location'] = $petfinder->getLocation();
        $response['dog']['shelter_id'] = $petfinder->getShelterId();
        $response['source'] = $petfinder->getSource();
        return $response;
    }

    /**
     * Adds others Dogs at Shelter to the getDog function Response.
     *
     * @param array $response
     * @param Petfinder $petfinder
     *
     * @return mixed
     */
    private function _addShelterDogsToResponse(array $response, Petfinder $petfinder)
    {
        $shelterDogs = $petfinder->getShelterDogs($petfinder->getShelterId());
        $response['shelter']['dogs'] = null;
        if($shelterDogs) {
            foreach ($shelterDogs as $dog)
            {
                if($dog->animal == 'Dog') {
                    $petfinder = new Petfinder;
                    $petfinder->getDogData($dog->id);

                    $data = [];
                    $data['dog']['id'] = $dog->id;
                    $data = $this->_prepareResponseFromDog($data, $petfinder);

                    $response['shelter']['dogs'][] = $data;
                }
            }
        }
        return $response;
    }

    /**
     * Add shelter details to response.
     *
     * @param array $response
     * @param Petfinder $petfinder
     *
     * @return mixed
     */
    private function _addShelterDetailsToResponse(array $response, Petfinder $petfinder)
    {
        // Add Shelter details to the response.
        try {
            $response['shelter']['details'] = $petfinder->getShelterDetails($petfinder->getShelterId());
            return $response;
        } catch (Exception $e) {
            // Add null in case of Exception.
            $response['shelter']['details'] = null;
            return $response;
        }
    }
}
